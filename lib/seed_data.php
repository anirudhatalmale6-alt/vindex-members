<?php
require_once __DIR__ . '/db.php';

/** Run the schema.sql file against the database (idempotent — CREATE TABLE IF NOT EXISTS). */
function run_schema(): void {
    $sql = file_get_contents(__DIR__ . '/../schema.sql');
    // strip full-line SQL comments so they don't get glued onto the next statement
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    // schema has no stored procedures, so splitting on ; is safe
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '') continue;
        db()->exec($stmt);
    }
}

/** Create a user (idempotent by email). Returns id. */
function seed_user(string $name, string $email, string $password, string $role): int {
    $email = strtolower($email);
    $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $ins = db()->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)');
    $ins->execute([$name, $email, password_hash($password, $algo), $role]);
    return (int)db()->lastInsertId();
}

/** The client's starter section list (they can rename / reorder / delete any of these). */
function starter_sections(): array {
    // [title_en, title_da, visibility]
    return [
        ['Overview', 'Overblik', 'all'],
        ['Paradigme', 'Paradigme', 'all'],
        ['Implementation', 'Implementering', 'all'],
        ['Compliance', 'Compliance', 'all'],
        ['Strategy', 'Strategi', 'leadership'],
        ['Employee Training', 'Medarbejdertræning', 'all'],
        ['HR – Hiring', 'HR – rekruttering', 'leadership'],
        ['Legal', 'Jura', 'leadership'],
        ['Kundeservice – AI receptionist & chatbot', 'Kundeservice – AI-receptionist & chatbot', 'all'],
        ['Operations – meetings & project management', 'Drift – møder & projektstyring', 'all'],
        ['Sales – speed to lead', 'Salg – speed to lead', 'all'],
        ['Traditional AI', 'Traditionel AI', 'all'],
        ['Competitor & market news', 'Konkurrent- & markedsnyheder', 'leadership'],
        ['Business API update system', 'Business API-opdateringssystem', 'all'],
        ['CRM', 'CRM', 'all'],
        ['Fraud Awareness – Process Discipline Design', 'Svindelbevidsthed – procesdisciplin', 'all'],
        ['6–8 Layered Thinking Model', '6–8-lags tænkningsmodel', 'leadership'],
        ['Principles', 'Principper', 'all'],
        ['Frameworks', 'Rammeværk', 'all'],
        ['Studies', 'Studier', 'all'],
        ['Tools & Platforms', 'Værktøjer & platforme', 'all'],
        ['3 Layers of Intelligence', '3 lag af intelligens', 'all'],
        ['News', 'Nyheder', 'all'],
    ];
}

/** Insert the starter sections if the table is empty. Returns [title_en => id]. */
function seed_sections(): array {
    $count = (int)db()->query('SELECT COUNT(*) FROM sections')->fetchColumn();
    $ids = [];
    if ($count > 0) return $ids;
    $ins = db()->prepare('INSERT INTO sections (title_en,title_da,sort_order,visibility) VALUES (?,?,?,?)');
    $order = 10;
    foreach (starter_sections() as $s) {
        $ins->execute([$s[0], $s[1], $order, $s[2]]);
        $ids[$s[0]] = (int)db()->lastInsertId();
        $order += 10;
    }
    return $ids;
}

/** Add a block and return its id. */
function seed_block(int $sectionId, ?int $parent, string $type, string $tEn, string $tDa, ?string $bEn, ?string $bDa, string $vis, int $order): int {
    $ins = db()->prepare('INSERT INTO blocks (section_id,parent_id,type,title_en,title_da,body_en,body_da,visibility,sort_order) VALUES (?,?,?,?,?,?,?,?,?)');
    $ins->execute([$sectionId, $parent, $type, $tEn, $tDa, $bEn, $bDa, $vis, $order]);
    return (int)db()->lastInsertId();
}

/** A little demo content so the walkthrough shows nested fold-outs and tier gating. */
function seed_demo_content(array $sectionIds): void {
    if (empty($sectionIds['Overview'])) return;
    if ((int)db()->query('SELECT COUNT(*) FROM blocks')->fetchColumn() > 0) return;

    $ov = $sectionIds['Overview'];
    seed_block($ov, null, 'text', 'Welcome to the Vindex knowledge base', 'Velkommen til Vindex-vidensbasen',
        "This is your company's living knowledge base for how to use AI in operations. Use the menu on the left to move between sections.\n\nSections and content are maintained by your administrator, and everything stays current as the field moves.",
        "Dette er jeres virksomheds levende vidensbase om, hvordan I bruger AI i driften. Brug menuen til venstre for at skifte mellem sektioner.\n\nSektioner og indhold vedligeholdes af jeres administrator og holdes altid opdateret.",
        'all', 10);

    $how = seed_block($ov, null, 'foldout', 'How to read this platform', 'Sådan læser du platformen', null, null, 'all', 20);
    seed_block($ov, $how, 'text', '', '',
        "Each section in the left menu opens here in the main panel. Where you see an arrow, click the heading to fold it open and reveal more detail underneath.",
        "Hver sektion i venstremenuen åbnes her i hovedpanelet. Hvor du ser en pil, kan du klikke på overskriften for at folde den ud og vise flere detaljer.",
        'all', 10);
    $nested = seed_block($ov, $how, 'foldout', 'Fold-outs can nest as deep as you need', 'Udfoldninger kan indlejres så dybt, du har brug for', null, null, 'all', 20);
    seed_block($ov, $nested, 'text', '', '',
        "Like this — a fold-out inside a fold-out. Your administrator can build these to any depth from the admin panel.",
        "Sådan her — en udfoldning inde i en udfoldning. Din administrator kan bygge disse i vilkårlig dybde fra admin-panelet.",
        'all', 10);

    seed_block($ov, null, 'foldout', 'Leadership-only example', 'Kun for ledelsen (eksempel)',
        "Only Leadership and the administrator can see this block. An Employee account never receives it — the filtering happens on the server.",
        "Kun ledelsen og administratoren kan se denne blok. En medarbejderkonto modtager den aldrig — filtreringen sker på serveren.",
        'leadership', 30);

    if (!empty($sectionIds['Frameworks'])) {
        $fw = $sectionIds['Frameworks'];
        seed_block($fw, null, 'text', 'Frameworks', 'Rammeværk',
            "Reusable frameworks your teams can apply. Add each framework as a fold-out with its steps and links to studies.",
            "Genanvendelige rammeværk, som jeres teams kan bruge. Tilføj hvert rammeværk som en udfoldning med trin og links til studier.",
            'all', 10);
    }
}
