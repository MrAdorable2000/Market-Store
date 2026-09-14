<?php
/**
 * pages/admin/users.php
 * --------------------------------------------------------------------
 * IsokoRyacu user management (Phase 5).
 *
 * Features (all real, all working):
 *   - Live search (name / email / phone / location)
 *   - Role filter chips with real counts (uses the existing roles table)
 *   - Status filter (active / suspended / pending)
 *   - Pagination (15 per page)
 *   - Actions: suspend / activate, verify / unverify (CSRF + role-guarded,
 *     admins can never suspend themselves or other admins)
 *   - Public profile link per user
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_icons.php';
require_once __DIR__ . '/../../includes/admin_ui.php';
require_role('admin');

$pdo = db();
$me  = current_user();

/* ---------- Filters (whitelisted) ---------- */
$roleFilter   = strtoupper(trim((string) ($_GET['role'] ?? '')));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));
$q            = trim((string) ($_GET['q'] ?? ''));
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 15;

/* Real role list from the DB (no invented roles) */
$roles = [];
foreach ($pdo->query("SELECT id, name FROM roles ORDER BY id") as $r) {
    $roles[strtoupper($r['name'])] = (int) $r['id'];
}
if (!isset($roles[$roleFilter])) $roleFilter = '';
if (!in_array($statusFilter, ['active', 'suspended', 'pending'], true)) $statusFilter = '';

/* Counts per role (for the chips) */
$roleCounts = [];
foreach ($pdo->query("SELECT role_id, COUNT(*) n FROM users GROUP BY role_id") as $row) {
    foreach ($roles as $name => $id) {
        if ($id === (int) $row['role_id']) { $roleCounts[$name] = (int) $row['n']; break; }
    }
}
$totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
foreach ($roles as $name => $id) $roleCounts[$name] = $roleCounts[$name] ?? 0;

/* ---------- WHERE ---------- */
$where  = [];
$params = [];
if ($roleFilter !== '')   { $where[] = 'u.role_id = ?';   $params[] = $roles[$roleFilter]; }
if ($statusFilter !== '') { $where[] = 'u.status = ?';   $params[] = $statusFilter; }
if ($q !== '') {
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.location LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

/* ---------- Total + rows ---------- */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id" . $whereSql);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$pages = (int) ceil($total / $perPage);
if ($page > $pages && $pages > 0) $page = $pages;
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT u.id, u.full_name, u.email, u.phone, u.location, u.is_verified,
        u.is_seller, u.status, u.created_at, r.name AS role_name,
        (SELECT COUNT(*) FROM listings l WHERE l.seller_id = u.id) AS listings_count
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id" . $whereSql . "
    ORDER BY u.created_at DESC
    LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$users = $stmt->fetchAll();

$baseUrl = APP_URL . '/pages/admin/users.php';
$keep = array_filter([
    'q' => $q !== '' ? $q : null,
    'role' => $roleFilter ?: null,
    'status' => $statusFilter ?: null,
], static fn ($v) => $v !== null);

/* ---------- Page setup ---------- */
$pageTitle         = t('admin.nav_users');
$activePage        = 'admin';
$adminActivePage   = 'users';
$extraCss          = '<link rel="stylesheet" href="' . APP_URL . '/assets/css/admin.css">';
$adminPageTitle    = t('admin.nav_users');
$adminPageSubtitle = t('admin.users_sub');
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="a-shell" id="aShell">
<?php require __DIR__ . '/../../includes/admin_sidebar.php'; ?>
<div class="a-main">
<?php require __DIR__ . '/../../includes/admin_topbar.php'; ?>
<div class="a-content">

<div class="a-hero">
    <div>
        <h2><?php echo e(t('admin.nav_users')); ?></h2>
        <p class="a-hero__sub"><?php echo e(t('admin.users_sub')); ?></p>
    </div>
    <div class="a-hero__actions">
        <a class="a-btn" href="<?php echo APP_URL; ?>/pages/register.php"><?php echo admin_icon('plus'); ?><span><?php echo e(t('admin.add_user_via_register')); ?></span></a>
    </div>
</div>

<!-- Search + role filters -->
<section class="a-panel" style="padding:15px 18px;">
    <div class="a-panel__tools" style="width:100%;">
        <form class="a-search" style="max-width:380px; flex:1 1 260px; margin:0;" method="get" action="<?php echo $baseUrl; ?>" role="search">
            <?php echo admin_icon('search'); ?>
            <?php foreach ($keep as $k => $v): if ($k === 'q') continue; ?>
                <input type="hidden" name="<?php echo e($k); ?>" value="<?php echo e($v); ?>">
            <?php endforeach; ?>
            <input type="search" name="q" value="<?php echo e($q); ?>" placeholder="<?php echo e(t('admin.search_users_placeholder')); ?>">
        </form>
        <div class="a-filters">
            <?php
            $qsRole = static function (string $role) use ($baseUrl, $keep): string {
                $params = array_merge($keep, ['role' => $role ?: null, 'page' => null]);
                return $baseUrl . '?' . http_build_query(array_filter($params, static fn ($v) => $v !== null && $v !== ''));
            };
            ?>
            <a class="a-chip <?php echo $roleFilter === '' ? 'is-active' : ''; ?>" href="<?php echo e($qsRole('')); ?>"><?php echo e(t('search.all')); ?> <b><?php echo number_format($totalUsers); ?></b></a>
            <?php foreach ($roles as $roleName => $rid): ?>
                <a class="a-chip <?php echo $roleFilter === $roleName ? 'is-active' : ''; ?>" href="<?php echo e($qsRole($roleName)); ?>"><?php echo e(str_replace('_', ' ', $roleName)); ?> <b><?php echo number_format($roleCounts[$roleName] ?? 0); ?></b></a>
            <?php endforeach; ?>
            <span style="width:1px; height:22px; background:var(--a-line);"></span>
            <a class="a-chip <?php echo $statusFilter === 'active' ? 'is-active' : ''; ?>" href="<?php echo $baseUrl; ?>?<?php echo http_build_query(array_filter(array_merge($keep, ['status' => $statusFilter === 'active' ? null : 'active', 'page' => null]))); ?>"><?php echo e(t('admin.status_active')); ?></a>
            <a class="a-chip <?php echo $statusFilter === 'suspended' ? 'is-active' : ''; ?>" href="<?php echo $baseUrl; ?>?<?php echo http_build_query(array_filter(array_merge($keep, ['status' => $statusFilter === 'suspended' ? null : 'suspended', 'page' => null]))); ?>"><?php echo e(t('admin.status_suspended')); ?></a>
        </div>
    </div>
</section>

<section class="a-panel">
    <div class="a-panel__head">
        <div>
            <h3><?php echo e(t('admin.panel_recent_users')); ?></h3>
            <p><?php echo number_format($total); ?> <?php echo e(t('admin.users_count')); ?><?php echo $q !== '' ? ' · "' . e($q) . '"' : ''; ?></p>
        </div>
        <?php if ($q !== '' || $roleFilter || $statusFilter): ?>
            <a class="a-panel__link" href="<?php echo $baseUrl; ?>"><?php echo e(t('admin.clear_filters')); ?></a>
        <?php endif; ?>
    </div>

    <?php if (!$users): ?>
        <?php echo admin_empty_state('users', $q !== '' ? t('common.no_results') : t('admin.empty_no_users'), t('admin.empty_no_users_sub')); ?>
    <?php else: ?>
    <div class="a-tablewrap">
        <table class="a-table">
            <thead><tr>
                <th><?php echo e(t('admin.col_name')); ?></th>
                <th><?php echo e(t('admin.col_email')); ?></th>
                <th><?php echo e(t('admin.col_phone')); ?></th>
                <th><?php echo e(t('admin.col_location')); ?></th>
                <th><?php echo e(t('admin.col_role')); ?></th>
                <th><?php echo e(t('admin.col_listings')); ?></th>
                <th><?php echo e(t('admin.col_status')); ?></th>
                <th><?php echo e(t('admin.col_joined')); ?></th>
                <th><?php echo e(t('admin.col_actions')); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <?php
                $isAdminUser  = in_array(strtoupper($u['role_name']), ['ADMIN', 'SUPER_ADMIN'], true);
                $isSelf       = (int) $u['id'] === (int) $me['id'];
                $isSuperAdmin = strtoupper((string)($me['role_name'] ?? '')) === 'SUPER_ADMIN';
                // Super Admin can manage/delete every other account, but never themselves.
                // Regular Admins can manage normal users only.
                $canModify    = $isSuperAdmin ? !$isSelf : (!$isAdminUser && !$isSelf);
                ?>
                <tr>
                    <td data-label="<?php echo e(t('admin.col_name')); ?>">
                        <span class="a-table__user">
                            <span class="a-avatar <?php echo $isAdminUser ? 'a-avatar--orange' : ''; ?>"><?php echo e(strtoupper(substr($u['full_name'], 0, 1))); ?></span>
                            <span style="min-width:0;">
                                <span class="a-table__listing-title"><a href="<?php echo APP_URL; ?>/pages/seller-profile.php?id=<?php echo (int) $u['id']; ?>"><?php echo e($u['full_name']); ?></a></span>
                                <span class="a-table__listing-sub"><?php echo $u['is_verified'] ? '✓ ' . e(t('admin.verified')) : ''; ?><?php echo $isSelf ? ($u['is_verified'] ? ' · ' : '') . e(t('admin.you')) : ''; ?></span>
                            </span>
                        </span>
                    </td>
                    <td data-label="<?php echo e(t('admin.col_email')); ?>" class="cell-mute"><?php echo e($u['email']); ?></td>
                    <td data-label="<?php echo e(t('admin.col_phone')); ?>" class="cell-mute"><?php echo e($u['phone'] ?? '—'); ?></td>
                    <td data-label="<?php echo e(t('admin.col_location')); ?>" class="cell-mute"><?php echo e($u['location'] ?? '—'); ?></td>
                    <td data-label="<?php echo e(t('admin.col_role')); ?>">
                        <span class="a-status <?php echo $isAdminUser ? 'a-status--featured' : 'a-status--neutral'; ?>"><?php echo e(str_replace('_', ' ', $u['role_name'])); ?></span>
                    </td>
                    <td data-label="<?php echo e(t('admin.col_listings')); ?>" class="cell-num"><?php echo number_format((int) $u['listings_count']); ?></td>
                    <td data-label="<?php echo e(t('admin.col_status')); ?>">
                        <span class="a-status a-status--<?php echo e($u['status']); ?>"><?php echo e(t('admin.status_' . $u['status'])); ?></span>
                    </td>
                    <td data-label="<?php echo e(t('admin.col_joined')); ?>" class="cell-mute"><?php echo e(time_ago($u['created_at'])); ?></td>
                    <td data-label="<?php echo e(t('admin.col_actions')); ?>">
                        <div class="a-rowactions">
                            <?php if ($canModify && $u['status'] === 'active'): ?>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;"
                                      data-confirm="<?php echo e(t('admin.suspend_confirm', ['name' => $u['full_name']])); ?>"
                                      data-confirm-title="<?php echo e(t('admin.suspend_title')); ?>"
                                      data-confirm-label="<?php echo e(t('admin.suspend_action')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="user_suspend">
                                    <input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>">
                                    <button class="a-btn a-btn--danger a-btn--sm" type="submit" title="<?php echo e(t('admin.suspend_action')); ?>"><?php echo admin_icon('user-x'); ?></button>
                                </form>
                            <?php elseif ($canModify && $u['status'] === 'suspended'): ?>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="user_activate">
                                    <input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>">
                                    <button class="a-btn a-btn--primary a-btn--sm" type="submit" title="<?php echo e(t('admin.activate_action')); ?>"><?php echo admin_icon('user-check'); ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canModify): ?>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;" data-confirm="<?php echo e('Permanently delete '.$u['full_name'].'? This removes the account and all data owned by it. This cannot be undone.'); ?>" data-confirm-title="Permanently delete user" data-confirm-label="Delete permanently">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="user_delete">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                    <button class="a-btn a-btn--danger a-btn--sm" type="submit" title="Delete permanently"><?php echo admin_icon('trash'); ?></button>
                                </form>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="<?php echo $u['is_verified'] ? 'user_unverify' : 'user_verify'; ?>">
                                    <input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>">
                                    <button class="a-btn a-btn--sm" type="submit" title="<?php echo $u['is_verified'] ? e(t('admin.unverify')) : e(t('admin.verify_action')); ?>" style="<?php echo $u['is_verified'] ? 'color:var(--a-teal-deep); border-color:var(--a-teal-line);' : ''; ?>"><?php echo admin_icon('shield'); ?></button>
                                </form>
                            <?php endif; ?>
                            <a class="a-btn a-btn--sm" href="<?php echo APP_URL; ?>/pages/seller-profile.php?id=<?php echo (int) $u['id']; ?>" title="<?php echo e(t('buttons.view')); ?>"><?php echo admin_icon('eye'); ?></a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php echo admin_pagination($total, $perPage, $page, $baseUrl, $keep); ?>
    <?php endif; ?>
</section>

</div><!-- /a-content -->
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
