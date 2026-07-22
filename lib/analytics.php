<?php
require_once __DIR__ . '/../config.php';

/** Is any analytics provider configured? */
function analytics_enabled(): bool {
    return (defined('GOATCOUNTER_CODE') && GOATCOUNTER_CODE !== '')
        || (defined('MATOMO_URL') && MATOMO_URL !== '');
}

/**
 * Cookie-consent banner + consent-gated analytics loader.
 * - Renders nothing at all if no analytics provider is configured.
 * - Analytics (GoatCounter / Matomo) only load AFTER the visitor clicks Accept.
 * - The choice is remembered in localStorage ('vindex-consent'); Decline keeps them off.
 * The banner text carries data-en/data-da so the existing EN/DA toggle translates it.
 */
function render_consent_and_analytics(): string {
    if (!analytics_enabled()) return '';
    $gc = defined('GOATCOUNTER_CODE') ? (string)GOATCOUNTER_CODE : '';
    $mu = (defined('MATOMO_URL') && MATOMO_URL !== '') ? rtrim((string)MATOMO_URL, '/') . '/' : '';
    $ms = defined('MATOMO_SITE_ID') ? (string)MATOMO_SITE_ID : '1';
    ob_start(); ?>
<div id="cookie-consent" class="cookie-consent" hidden role="dialog" aria-live="polite">
  <div class="cc-inner">
    <p class="cc-text" data-en="We use privacy-friendly analytics (no ads, no cross-site tracking) to see how the site is used. You choose whether to allow it." data-da="Vi bruger privatlivsvenlig statistik (ingen reklamer, ingen sporing på tværs af websites) for at se, hvordan sitet bruges. Du bestemmer, om det må tillades.">We use privacy-friendly analytics (no ads, no cross-site tracking) to see how the site is used. You choose whether to allow it.</p>
    <div class="cc-actions">
      <button type="button" class="btn btn-ghost btn-sm" id="cc-decline" data-en="Decline" data-da="Afvis">Decline</button>
      <button type="button" class="btn btn-primary btn-sm" id="cc-accept" data-en="Accept" data-da="Accepter">Accept</button>
    </div>
  </div>
</div>
<script>
(function(){
  var KEY='vindex-consent';
  var GC=<?= json_encode($gc) ?>, MU=<?= json_encode($mu) ?>, MS=<?= json_encode($ms) ?>;
  function loadAnalytics(){
    if(window.__vdxAnalytics)return; window.__vdxAnalytics=true;
    if(GC){var s=document.createElement('script');s.async=true;s.setAttribute('data-goatcounter',GC);s.src='//gc.zgo.at/count.js';document.head.appendChild(s);}
    if(MU){var _paq=window._paq=window._paq||[];_paq.push(['disableCookies']);_paq.push(['trackPageView']);_paq.push(['enableLinkTracking']);_paq.push(['setTrackerUrl',MU+'matomo.php']);_paq.push(['setSiteId',MS]);var g=document.createElement('script');g.async=true;g.src=MU+'matomo.js';document.head.appendChild(g);}
  }
  var banner=document.getElementById('cookie-consent');
  var choice=null; try{choice=localStorage.getItem(KEY);}catch(e){}
  if(choice==='yes'){loadAnalytics();}
  else if(choice!=='no'&&banner){banner.hidden=false;}
  function set(v){try{localStorage.setItem(KEY,v);}catch(e){}if(banner)banner.hidden=true;if(v==='yes')loadAnalytics();}
  var a=document.getElementById('cc-accept'),d=document.getElementById('cc-decline');
  if(a)a.addEventListener('click',function(){set('yes');});
  if(d)d.addEventListener('click',function(){set('no');});
})();
</script>
<?php
    return ob_get_clean();
}
