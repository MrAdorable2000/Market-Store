<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/smart_features.php';

$ins = marketplace_insights_real();
$totals = $ins['totals'];
$week = $ins['new_week'];
$maxViews = max(1, (int)($ins['most_viewed'][0]['views_count'] ?? 0));
$maxFavs = max(1, (int)($ins['most_favorited'][0]['favorites_count'] ?? 0));
$maxCatViews = max(1, (int)($ins['categories'][0]['views'] ?? 0));
$maxSearch = max(1, (int)($ins['top_searches'][0]['metric_count'] ?? 0));
$rentalMap = [];
foreach ($ins['rental_status'] as $rs) $rentalMap[$rs['status']] = (int)$rs['n'];
$rentalTotal = array_sum($rentalMap);
$pageTitle = t('nav.insights');
$activePage = 'insights';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.mi-page{padding:28px 0 70px}.mi-back{margin-bottom:18px}.mi-hero{position:relative;overflow:hidden;border-radius:28px;padding:34px;background:linear-gradient(135deg,#0d4f4a 0%,#123d3a 58%,#0b2d2b 100%);color:#fff;box-shadow:0 22px 60px rgba(9,58,55,.18)}
.mi-hero:before,.mi-hero:after{content:"";position:absolute;border:1px solid rgba(255,255,255,.10);border-radius:50%}.mi-hero:before{width:360px;height:360px;right:-100px;top:-180px}.mi-hero:after{width:250px;height:250px;right:80px;bottom:-190px}.mi-hero>*{position:relative;z-index:1}.mi-kicker{display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#ffd37b;margin-bottom:12px}.mi-live{width:8px;height:8px;border-radius:50%;background:#57e39b;box-shadow:0 0 0 5px rgba(87,227,155,.12)}
.mi-title{font-size:clamp(32px,4vw,52px);line-height:1.02;margin:0 0 12px;color:#fff}.mi-sub{max-width:720px;margin:0;color:rgba(255,255,255,.72);font-size:15px;line-height:1.7}.mi-hero-grid{display:grid;grid-template-columns:1.4fr .6fr;gap:24px;align-items:end}.mi-rating{justify-self:end;text-align:right}.mi-rating strong{font-size:40px;display:block;color:#ffd37b}.mi-rating span{color:rgba(255,255,255,.65);font-size:13px}
.mi-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:16px}.mi-stat{background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:17px}.mi-stat-label{font-size:12px;color:rgba(255,255,255,.65);font-weight:700}.mi-stat-value{font-size:27px;font-weight:850;margin:6px 0 2px}.mi-stat-delta{font-size:12px;color:#8ce8b7}.mi-section-head{display:flex;align-items:end;justify-content:space-between;gap:20px;margin:38px 0 15px}.mi-section-head h2{margin:0;font-size:23px}.mi-section-head p{margin:5px 0 0;color:var(--muted);font-size:13px}.mi-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.mi-card{background:var(--surface,#fff);border:1px solid var(--line,#e7eeee);border-radius:22px;padding:22px;box-shadow:0 10px 30px rgba(20,50,48,.05)}.mi-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:20px}.mi-card-head h3{margin:0;font-size:17px}.mi-card-head span{font-size:11px;color:var(--muted);background:var(--surface-2,#f4f8f8);padding:6px 9px;border-radius:999px}.mi-row{display:grid;grid-template-columns:minmax(100px,1fr) 1.5fr 48px;align-items:center;gap:12px;margin:13px 0}.mi-row-name{font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.mi-track{height:9px;background:#edf3f2;border-radius:999px;overflow:hidden}.mi-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,var(--brand-500,#16aaa3),#f2a126)}.mi-number{text-align:right;font-size:12px;color:var(--muted);font-weight:800}.mi-product{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--line,#edf1f1);text-decoration:none;color:inherit}.mi-product:last-child{border-bottom:0}.mi-product-icon{width:42px;height:42px;border-radius:13px;background:linear-gradient(135deg,#e5f7f5,#fff2da);display:grid;place-items:center;color:var(--brand-600,#087f7b);font-weight:900}.mi-product-main{min-width:0;flex:1}.mi-product-title{font-weight:750;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.mi-product-meta{font-size:11px;color:var(--muted);margin-top:3px}.mi-pill{font-size:11px;font-weight:800;padding:5px 8px;border-radius:999px;background:#eef9f4;color:#148553}.mi-location-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.mi-location{padding:15px;border:1px solid var(--line,#e7eeee);border-radius:17px;text-decoration:none;color:inherit;transition:.2s}.mi-location:hover{transform:translateY(-2px);border-color:#9fded9;box-shadow:0 10px 25px rgba(10,100,95,.08)}.mi-location strong{display:block;font-size:14px}.mi-location small{display:block;color:var(--muted);font-size:11px;margin-top:6px}.mi-demand{display:grid;gap:10px}.mi-demand a{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:12px 14px;border-radius:14px;background:var(--surface-2,#f7faf9);text-decoration:none;color:inherit}.mi-demand b{font-size:13px}.mi-demand span{font-size:11px;color:#148553;font-weight:800}.mi-empty{padding:25px 10px;text-align:center;color:var(--muted);font-size:13px}.mi-footer-note{text-align:center;color:var(--muted);font-size:12px;margin-top:35px}
@media(max-width:900px){.mi-hero-grid{grid-template-columns:1fr}.mi-rating{justify-self:start;text-align:left}.mi-stats{grid-template-columns:1fr 1fr}.mi-grid{grid-template-columns:1fr}.mi-location-grid{grid-template-columns:1fr 1fr}}
@media(max-width:560px){.mi-page{padding-top:16px}.mi-hero{padding:24px;border-radius:22px}.mi-stats{grid-template-columns:1fr 1fr}.mi-stat{padding:13px}.mi-stat-value{font-size:23px}.mi-location-grid{grid-template-columns:1fr}.mi-card{padding:17px}.mi-row{grid-template-columns:100px 1fr 38px}}
</style>
<div class="container mi-page">
  <div class="mi-back"><?php echo back_button(APP_URL . '/', t('buttons.back'), 'solid'); ?></div>

  <section class="mi-hero">
    <div class="mi-hero-grid">
      <div>
        <div class="mi-kicker"><i class="mi-live"></i> Live marketplace intelligence</div>
        <h1 class="mi-title">Market insights</h1>
        <p class="mi-sub">Understand what is happening across IsokoRyacu — products people view, save and search for, the categories moving fastest, and real marketplace activity.</p>
      </div>
      <div class="mi-rating"><strong><?php echo number_format($totals['avg_rating'],1); ?></strong><span>average marketplace rating · <?php echo number_format($totals['reviews']); ?> reviews</span></div>
    </div>
    <div class="mi-stats">
      <div class="mi-stat"><div class="mi-stat-label">Active listings</div><div class="mi-stat-value"><?php echo number_format($totals['listings']); ?></div><div class="mi-stat-delta">+<?php echo (int)$week['listings']; ?> this week</div></div>
      <div class="mi-stat"><div class="mi-stat-label">Active sellers</div><div class="mi-stat-value"><?php echo number_format($totals['sellers']); ?></div><div class="mi-stat-delta"><?php echo number_format($totals['users']); ?> members</div></div>
      <div class="mi-stat"><div class="mi-stat-label">Product views</div><div class="mi-stat-value"><?php echo number_format($totals['views']); ?></div><div class="mi-stat-delta"><?php echo number_format($totals['favorites']); ?> saves</div></div>
      <div class="mi-stat"><div class="mi-stat-label">Rental requests</div><div class="mi-stat-value"><?php echo number_format($totals['rentals']); ?></div><div class="mi-stat-delta">+<?php echo (int)$week['rentals']; ?> this week</div></div>
    </div>
  </section>

  <div class="mi-section-head"><div><h2>What is trending</h2><p>Real activity from products currently active on the marketplace.</p></div></div>
  <div class="mi-grid">
    <div class="mi-card"><div class="mi-card-head"><h3>🔥 Most viewed products</h3><span>Top 5</span></div>
      <?php if (!$ins['most_viewed']): ?><div class="mi-empty"><?php echo e(t('empty.no_listings')); ?></div><?php else: foreach($ins['most_viewed'] as $row): $pct=(int)round(100*(int)$row['views_count']/$maxViews); ?><a class="mi-product" href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$row['id']; ?>"><div class="mi-product-icon">↗</div><div class="mi-product-main"><div class="mi-product-title"><?php echo e($row['title']); ?></div><div class="mi-product-meta"><?php echo e($row['location'] ?: 'IsokoRyacu'); ?> · <?php echo number_format((int)$row['favorites_count']); ?> saves</div></div><span class="mi-pill"><?php echo number_format((int)$row['views_count']); ?></span></a><?php endforeach; endif; ?>
    </div>
    <div class="mi-card"><div class="mi-card-head"><h3>❤️ Most saved</h3><span>Top 5</span></div>
      <?php if (!$ins['most_favorited']): ?><div class="mi-empty"><?php echo e(t('empty.no_favorites_yet')); ?></div><?php else: foreach($ins['most_favorited'] as $row): $pct=(int)round(100*(int)$row['favorites_count']/$maxFavs); ?><a class="mi-product" href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$row['id']; ?>"><div class="mi-product-icon">♡</div><div class="mi-product-main"><div class="mi-product-title"><?php echo e($row['title']); ?></div><div class="mi-product-meta"><?php echo e($row['location'] ?: 'IsokoRyacu'); ?> · <?php echo number_format((int)$row['views_count']); ?> views</div></div><span class="mi-pill"><?php echo number_format((int)$row['favorites_count']); ?></span></a><?php endforeach; endif; ?>
    </div>
  </div>

  <div class="mi-section-head"><div><h2>Marketplace pulse</h2><p>See where attention and demand are concentrated.</p></div></div>
  <div class="mi-grid">
    <div class="mi-card"><div class="mi-card-head"><h3>📊 Category activity</h3><span>By views</span></div><?php if(!$ins['categories']): ?><div class="mi-empty"><?php echo e(t('empty.no_listings')); ?></div><?php else: foreach(array_slice($ins['categories'],0,8) as $row): $pct=(int)round(100*(int)$row['views']/$maxCatViews); ?><div class="mi-row"><div class="mi-row-name"><?php echo e(t_category($row['name_key']??null,$row['name'])); ?></div><div class="mi-track"><div class="mi-fill" style="width:<?php echo $pct; ?>%"></div></div><div class="mi-number"><?php echo (int)$row['listing_count']; ?></div></div><?php endforeach; endif; ?></div>
    <div class="mi-card"><div class="mi-card-head"><h3>🔎 Popular searches</h3><span>Real searches</span></div><?php if(!$ins['top_searches']): ?><div class="mi-empty"><?php echo e(t('insights.no_searches_yet')); ?></div><?php else: foreach($ins['top_searches'] as $row): $pct=(int)round(100*(int)$row['metric_count']/$maxSearch); ?><div class="mi-row"><div class="mi-row-name"><?php echo e($row['metric_value']); ?></div><div class="mi-track"><div class="mi-fill" style="width:<?php echo $pct; ?>%"></div></div><div class="mi-number"><?php echo (int)$row['metric_count']; ?></div></div><?php endforeach; endif; ?></div>
  </div>

  <?php if($ins['locations']): ?><div class="mi-section-head"><div><h2>📍 Where the marketplace is active</h2><p>Locations with the strongest listing, view and save activity.</p></div></div><div class="mi-location-grid"><?php foreach($ins['locations'] as $loc): ?><a class="mi-location" href="<?php echo APP_URL; ?>/pages/locations.php"><strong><?php echo e($loc['city']); ?></strong><small><?php echo (int)$loc['listing_count']; ?> listings · <?php echo number_format((int)$loc['views']); ?> views · <?php echo number_format((int)$loc['favorites']); ?> saves</small></a><?php endforeach; ?></div><?php endif; ?>

  <div class="mi-section-head"><div><h2>Demand & rentals</h2><p>Signals that help sellers understand what deserves attention.</p></div></div>
  <div class="mi-grid">
    <div class="mi-card"><div class="mi-card-head"><h3>🔥 High-demand products</h3><span>Save rate</span></div><?php if(!$ins['high_demand']): ?><div class="mi-empty"><?php echo e(t('insights.no_demand_data')); ?></div><?php else: ?><div class="mi-demand"><?php foreach($ins['high_demand'] as $row): ?><a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$row['id']; ?>"><b><?php echo e(excerpt($row['title'],42)); ?></b><span><?php echo e((string)$row['demand_score']); ?>% saved</span></a><?php endforeach; ?></div><?php endif; ?></div>
    <div class="mi-card"><div class="mi-card-head"><h3>🏠 Rental trends</h3><span>Last 30 days</span></div><?php if(!$rentalTotal): ?><div class="mi-empty"><?php echo e(t('insights.no_rentals_yet')); ?></div><?php else: foreach(['pending','approved','declined','cancelled','completed'] as $st): if(empty($rentalMap[$st])) continue; $pct=(int)round(100*$rentalMap[$st]/max(1,$rentalTotal)); ?><div class="mi-row"><div class="mi-row-name"><?php echo e(t('admin.status_'.$st)); ?></div><div class="mi-track"><div class="mi-fill" style="width:<?php echo $pct; ?>%"></div></div><div class="mi-number"><?php echo $rentalMap[$st]; ?></div></div><?php endforeach; ?><div class="mi-product-meta" style="margin-top:16px"><?php echo (int)$ins['rental_trend_rent']; ?> requests created in the last 30 days · <?php echo (int)($rentalMap['pending']??0); ?> pending</div><?php endif; ?></div>
  </div>

  <div class="mi-footer-note">Live data from IsokoRyacu marketplace activity · No demo figures</div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
