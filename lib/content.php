<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/** Turn plain admin-entered text into safe HTML: escape, autolink URLs, paragraphs. */
function render_richtext(?string $text): string {
    $text = trim((string)$text);
    if ($text === '') return '';
    $esc = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // autolink http(s) links (already escaped, so safe)
    $esc = preg_replace_callback('~(https?://[^\s<]+)~', function ($m) {
        $u = rtrim($m[1], '.,);');
        return '<a href="' . $u . '" target="_blank" rel="noopener noreferrer">' . $u . '</a>';
    }, $esc);
    $paras = preg_split('/\n{2,}/', $esc);
    $out = '';
    foreach ($paras as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $out .= '<p>' . nl2br($p) . '</p>';
    }
    return $out;
}

/** Sections the current viewer may see, ordered for the sidebar. */
function fetch_sections(bool $includeHidden = false): array {
    $where = $includeHidden ? '1=1' : visibility_sql();
    return db()->query("SELECT * FROM sections WHERE $where ORDER BY sort_order, id")->fetchAll();
}

/** All blocks of a section (visibility-filtered) as a map keyed by parent id (0 = top level). */
function fetch_block_map(int $sectionId, bool $includeHidden = false): array {
    $extra = $includeHidden ? '' : ('AND ' . visibility_sql());
    $stmt = db()->prepare("SELECT * FROM blocks WHERE section_id = ? $extra ORDER BY sort_order, id");
    $stmt->execute([$sectionId]);
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[(int)($r['parent_id'] ?? 0)][] = $r;
    }
    return $map;
}

/** Recursively render a block tree to HTML for the members view (bilingual via data-en/data-da). */
function render_blocks(array $map, ?int $parent = null): string {
    $key = $parent === null ? 0 : $parent;
    if (empty($map[$key])) return '';
    $html = '';
    foreach ($map[$key] as $b) {
        $id   = (int)$b['id'];
        $tEn  = $b['title_en'];
        $tDa  = $b['title_da'] !== '' ? $b['title_da'] : $b['title_en'];
        $bEn  = render_richtext($b['body_en']);
        $bDa  = render_richtext(($b['body_da'] ?? '') !== '' ? $b['body_da'] : $b['body_en']);
        $lead = $b['visibility'] === 'leadership'
            ? '<span class="badge lead" data-en="Leadership" data-da="Ledelse">Leadership</span>' : '';
        $children = render_blocks($map, $id);
        $rt = ($bEn !== '' || $bDa !== '')
            ? '<div class="rt" data-en="' . e($bEn) . '" data-da="' . e($bDa) . '">' . $bEn . '</div>' : '';

        if ($b['type'] === 'foldout') {
            $html .= '<div class="fold">'
                . '<button class="fold-head" type="button" aria-expanded="false">'
                . '<svg class="chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 6l6 6-6 6"/></svg>'
                . '<span class="fold-title" data-en="' . e($tEn) . '" data-da="' . e($tDa) . '">' . e($tEn) . '</span>'
                . $lead . '</button>'
                . '<div class="fold-body"><div class="fold-inner">' . $rt . $children . '</div></div>'
                . '</div>';
        } else {
            $title = ($tEn !== '' || $tDa !== '')
                ? '<h3 class="block-title" data-en="' . e($tEn) . '" data-da="' . e($tDa) . '">' . e($tEn) . '</h3>' : '';
            $html .= '<div class="text-block">' . $title . $rt . $lead . $children . '</div>';
        }
    }
    return $html;
}
