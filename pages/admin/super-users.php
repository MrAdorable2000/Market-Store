<?php
/**
 * pages/admin/super-users.php — Super Admin User Management
 * --------------------------------------------------------------------
 * Full CRUD for users + admin management:
 *   - Create users (select role, set password)
 *   - Edit user info
 *   - Suspend/activate/verify
 *   - Change roles (Super Admin only)
 *   - Delete (soft-delete via status=disabled)
 *
 * SECURITY: require_super_admin() — only SUPER_ADMIN can access.
 * Normal ADMINs are blocked. Super Admin can create other admins
 * but cannot delete the last remaining super admin.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_icons.php';
require_once __DIR__ . '/../../includes/admin_ui.php';
require_super_admin();

$pdo = db();
$me = current_user();
$myId = (int) $me['id'];

// --- Handle POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        flash_set('error', t('errors.invalid_token'));
        redirect(APP_URL . '/pages/admin/super-users.php');
    }
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create_user':
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $roleId = (int)($_POST['role_id'] ?? 0);
            $location = trim($_POST['location'] ?? '');

            // Validation
            if (mb_strlen($fullName) < 3) { flash_set('error', 'Name must be at least 3 characters.'); break; }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { flash_set('error', 'Invalid email address.'); break; }
            if (strlen($password) < 8) { flash_set('error', 'Password must be at least 8 characters.'); break; }

            // Check email uniqueness
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) { flash_set('error', 'Email already exists.'); break; }

            // Verify role exists
            $stmt = $pdo->prepare('SELECT id, name FROM roles WHERE id = ?');
            $stmt->execute([$roleId]);
            $role = $stmt->fetch();
            if (!$role) { flash_set('error', 'Invalid role selected.'); break; }

            // Prevent creating SUPER_ADMIN via this form (only seed/migration should)
            if (strtoupper($role['name']) === 'SUPER_ADMIN') { flash_set('error', 'Cannot create Super Admin accounts via this form.'); break; }

            $hash = password_hash($password, HASH_ALGO, ['cost' => HASH_COST]);
            $isSeller = strtoupper($role['name']) === 'SELLER' ? 1 : 0;

            $pdo->prepare(
                'INSERT INTO users (role_id, full_name, email, password_hash, phone, location, is_seller, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "active")'
            )->execute([$roleId, $fullName, $email, $hash, $phone ?: null, $location ?: null, $isSeller]);

            // Audit log
            try {
                $newId = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO audit_log (user_id, action, target_type, target_id, metadata, ip_address) VALUES (?, "user.create", "user", ?, ?, ?)')
                    ->execute([$myId, $newId, json_encode(['name' => $fullName, 'email' => $email, 'role' => $role['name']]), $_SERVER['REMOTE_ADDR'] ?? null]);
            } catch (PDOException $e) {}

            flash_set('success', 'User created successfully: ' . $fullName);
            break;

        case 'update_status':
            $userId = (int)($_POST['user_id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? '';
            if (!in_array($newStatus, ['active', 'suspended', 'pending'], true)) { flash_set('error', 'Invalid status.'); break; }

            // Prevent self-suspension
            if ($userId === $myId) { flash_set('error', 'You cannot change your own status.'); break; }

            // Prevent suspending other super admins
            $stmt = $pdo->prepare('SELECT u.id, r.name AS role_name FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE u.id = ?');
            $stmt->execute([$userId]);
            $target = $stmt->fetch();
            if (!$target) { flash_set('error', 'User not found.'); break; }
            if (strtoupper($target['role_name']) === 'SUPER_ADMIN') { flash_set('error', 'Cannot modify a Super Admin account.'); break; }

            $pdo->prepare('UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$newStatus, $userId]);

            try {
                $pdo->prepare('INSERT INTO audit_log (user_id, action, target_type, target_id, metadata, ip_address) VALUES (?, "user.status_change", "user", ?, ?, ?)')
                    ->execute([$myId, $userId, json_encode(['new_status' => $newStatus]), $_SERVER['REMOTE_ADDR'] ?? null]);
            } catch (PDOException $e) {}

            flash_set('success', 'User status updated to ' . $newStatus . '.');
            break;

        case 'change_role':
            $userId = (int)($_POST['user_id'] ?? 0);
            $newRoleId = (int)($_POST['new_role_id'] ?? 0);

            // Prevent self-role-change
            if ($userId === $myId) { flash_set('error', 'You cannot change your own role.'); break; }

            // Verify role exists
            $stmt = $pdo->prepare('SELECT id, name FROM roles WHERE id = ?');
            $stmt->execute([$newRoleId]);
            $newRole = $stmt->fetch();
            if (!$newRole) { flash_set('error', 'Invalid role.'); break; }

            // Cannot assign SUPER_ADMIN
            if (strtoupper($newRole['name']) === 'SUPER_ADMIN') { flash_set('error', 'Cannot assign Super Admin role.'); break; }

            // Check target isn't a super admin
            $stmt = $pdo->prepare('SELECT u.id, r.name AS role_name FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE u.id = ?');
            $stmt->execute([$userId]);
            $target = $stmt->fetch();
            if (!$target) { flash_set('error', 'User not found.'); break; }
            if (strtoupper($target['role_name']) === 'SUPER_ADMIN') { flash_set('error', 'Cannot change a Super Admin role.'); break; }

            $isSeller = strtoupper($newRole['name']) === 'SELLER' ? 1 : 0;
            $pdo->prepare('UPDATE users SET role_id = ?, is_seller = ?, updated_at = NOW() WHERE id = ?')->execute([$newRoleId, $isSeller, $userId]);

            try {
                $pdo->prepare('INSERT INTO audit_log (user_id, action, target_type, target_id, metadata, ip_address) VALUES (?, "user.role_change", "user", ?, ?, ?)')
                    ->execute([$myId, $userId, json_encode(['new_role' => $newRole['name']]), $_SERVER['REMOTE_ADDR'] ?? null]);
            } catch (PDOException $e) {}

            flash_set('success', 'User role changed to ' . $newRole['name'] . '.');
            break;

        case 'reset_password':
            $userId = (int)($_POST['user_id'] ?? 0);
            $newPassword = (string)($_POST['new_password'] ?? '');
            if (strlen($newPassword) < 8) { flash_set('error', 'Password must be at least 8 characters.'); break; }
            if ($userId === $myId) { flash_set('error', 'Use the profile page to change your own password.'); break; }

            // Check target isn't a super admin
            $stmt = $pdo->prepare('SELECT u.id, r.name AS role_name FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE u.id = ?');
            $stmt->execute([$userId]);
            $target = $stmt->fetch();
            if (!$target) { flash_set('error', 'User not found.'); break; }
            if (strtoupper($target['role_name']) === 'SUPER_ADMIN') { flash_set('error', 'Cannot reset a Super Admin password.'); break; }

            $hash = password_hash($newPassword, HASH_ALGO, ['cost' => HASH_COST]);
            $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')->execute([$hash, $userId]);

            try {
                $pdo->prepare('INSERT INTO audit_log (user_id, action, target_type, target_id, metadata, ip_address) VALUES (?, "user.password_reset", "user", ?, ?, ?)')
                    ->execute([$myId, $userId, json_encode([]), $_SERVER['REMOTE_ADDR'] ?? null]);
            } catch (PDOException $e) {}

            flash_set('success', 'Password reset successfully.');
            break;
    }
    redirect(APP_URL . '/pages/admin/super-users.php');
}

// --- Filters ---
$q = trim($_GET['q'] ?? '');
$roleFilter = strtoupper(trim($_GET['role'] ?? ''));
$statusFilter = strtolower(trim($_GET['status'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

// Load roles
$roles = $pdo->query("SELECT id, name FROM roles ORDER BY id")->fetchAll();

// Build query
$where = [];
$params = [];
if ($q) { $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)'; $like = "%$q%"; array_push($params, $like, $like, $like); }
if ($roleFilter) { $where[] = 'r.name = ?'; $params[] = $roleFilter; }
if (in_array($statusFilter, ['active', 'suspended', 'pending'], true)) { $where[] = 'u.status = ?'; $params[] = $statusFilter; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$countSql = "SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id=u.role_id $whereSql";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$totalUsers = (int) $stmt->fetchColumn();
$totalPages = max(1, ceil($totalUsers / $perPage));
$offset = ($page - 1) * $perPage;

// Load users
$stmt = $pdo->prepare("SELECT u.*, r.name AS role_name FROM users u INNER JOIN roles r ON r.id=u.role_id $whereSql ORDER BY u.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$users = $stmt->fetchAll();

// Role counts for chips
$roleCounts = [];
foreach ($pdo->query("SELECT r.name, COUNT(u.id) AS cnt FROM roles r LEFT JOIN users u ON u.role_id=r.id GROUP BY r.id, r.name") as $row) {
    $roleCounts[strtoupper($row['name'])] = (int) $row['cnt'];
}

$pageTitle = 'User Management';
$activePage = 'admin';
$adminActivePage = 'users';
$extraCss = '<link rel="stylesheet" href="' . APP_URL . '/assets/css/admin.css">';
$adminPageTitle = 'User Management';
$adminPageSubtitle = 'Complete CRUD — create, edit, suspend, change roles';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="a-shell" id="aShell">
<?php require __DIR__ . '/../../includes/admin_sidebar.php'; ?>
<div class="a-main">
<?php require __DIR__ . '/../../includes/admin_topbar.php'; ?>
<div class="a-content">

<div class="a-hero">
    <div>
        <h2>User Management</h2>
        <p class="a-hero__sub">Complete CRUD — create, edit, suspend, change roles</p>
    </div>
    <div class="a-hero__actions">
        <button type="button" class="a-btn a-btn--accent" onclick="document.getElementById('createUserModal').style.display='flex'"><?php echo admin_icon('plus'); ?><span>Create User</span></button>
    </div>
</div>

<!-- Search + Filters -->
<form method="get" action="" class="a-filters" style="margin-bottom:18px;">
    <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search name, email, phone..." class="a-filter-chip" style="flex:1;min-width:200px;">
    <select name="role" class="a-filter-chip<?php echo $roleFilter?' is-active':''; ?>">
        <option value="">All Roles</option>
        <?php foreach ($roles as $r): ?>
        <option value="<?php echo e($r['name']); ?>" <?php echo strtoupper($r['name'])===$roleFilter?'selected':''; ?>><?php echo e($r['name']); ?> (<?php echo $roleCounts[strtoupper($r['name'])] ?? 0; ?>)</option>
        <?php endforeach; ?>
    </select>
    <select name="status" class="a-filter-chip<?php echo $statusFilter?' is-active':''; ?>">
        <option value="">All Status</option>
        <option value="active" <?php echo $statusFilter==='active'?'selected':''; ?>>Active</option>
        <option value="suspended" <?php echo $statusFilter==='suspended'?'selected':''; ?>>Suspended</option>
        <option value="pending" <?php echo $statusFilter==='pending'?'selected':''; ?>>Pending</option>
    </select>
    <button type="submit" class="a-btn a-btn--sm">Filter</button>
</form>

<!-- Users Table -->
<section class="a-panel">
    <div class="a-table-wrap">
        <table class="a-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--a-muted);">No users found.</td></tr>
                <?php else: foreach ($users as $u):
                    $isSuperAdmin = strtoupper($u['role_name']) === 'SUPER_ADMIN';
                    $isMe = (int)$u['id'] === $myId;
                ?>
                <tr>
                    <td class="cell-primary"><?php echo e($u['full_name']); ?><?php echo $isMe ? ' <span style="color:var(--a-teal);font-size:10px;">(You)</span>' : ''; ?></td>
                    <td class="cell-mute"><?php echo e($u['email']); ?></td>
                    <td><span class="a-status a-status--<?php echo $isSuperAdmin?'active':(strtoupper($u['role_name'])==='ADMIN'?'active':'pending'); ?>"><?php echo e($u['role_name']); ?></span></td>
                    <td><span class="a-status a-status--<?php echo $u['status']==='active'?'active':'suspended'; ?>"><?php echo e($u['status']); ?></span></td>
                    <td class="cell-mute"><?php echo time_ago($u['created_at']); ?></td>
                    <td>
                        <?php if (!$isSuperAdmin && !$isMe): ?>
                        <select onchange="userAction(this, <?php echo (int)$u['id']; ?>, '<?php echo e($u['full_name']); ?>')" style="padding:5px 8px;border:1px solid var(--a-line);border-radius:6px;font-size:11px;">
                            <option value="">Actions...</option>
                            <?php if ($u['status'] === 'active'): ?>
                            <option value="suspend">Suspend</option>
                            <?php else: ?>
                            <option value="activate">Activate</option>
                            <?php endif; ?>
                            <option value="role">Change Role</option>
                            <option value="password">Reset Password</option>
                        </select>
                        <?php else: ?>
                        <span style="color:var(--a-muted);font-size:11px;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;gap:6px;justify-content:center;margin-top:16px;">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>" class="a-filter-chip<?php echo $p===$page?' is-active':''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</section>

</div>
</div>
</div>

<!-- Create User Modal -->
<div id="createUserModal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.5);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)this.style.display='none'">
    <div style="max-width:440px;width:100%;background:var(--a-card,#fff);border:1px solid var(--a-line,#e7ebec);border-radius:16px;padding:28px;box-shadow:0 24px 64px rgba(0,0,0,.2);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
            <h3 style="margin:0;font-size:17px;">Create New User</h3>
            <button type="button" onclick="document.getElementById('createUserModal').style.display='none'" style="background:none;border:0;font-size:20px;cursor:pointer;">×</button>
        </div>
        <form method="post" action="">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create_user">
            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Full Name</label>
                <input type="text" name="full_name" required style="width:100%;padding:9px 12px;border:1px solid var(--a-line,#e7ebec);border-radius:8px;font-size:13px;">
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Email</label>
                <input type="email" name="email" required style="width:100%;padding:9px 12px;border:1px solid var(--a-line,#e7ebec);border-radius:8px;font-size:13px;">
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Phone (optional)</label>
                <input type="text" name="phone" style="width:100%;padding:9px 12px;border:1px solid var(--a-line,#e7ebec);border-radius:8px;font-size:13px;">
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Role</label>
                <select name="role_id" required style="width:100%;padding:9px 12px;border:1px solid var(--a-line,#e7ebec);border-radius:8px;font-size:13px;">
                    <option value="">Select role...</option>
                    <?php foreach ($roles as $r): if (strtoupper($r['name']) === 'SUPER_ADMIN') continue; ?>
                    <option value="<?php echo (int)$r['id']; ?>"><?php echo e($r['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Password</label>
                <input type="password" name="password" required minlength="8" style="width:100%;padding:9px 12px;border:1px solid var(--a-line,#e7ebec);border-radius:8px;font-size:13px;">
            </div>
            <div style="margin-bottom:18px;">
                <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Location (optional)</label>
                <input type="text" name="location" style="width:100%;padding:9px 12px;border:1px solid var(--a-line,#e7ebec);border-radius:8px;font-size:13px;">
            </div>
            <button type="submit" class="a-btn a-btn--primary" style="width:100%;">Create User</button>
        </form>
    </div>
</div>

<!-- Hidden forms for actions -->
<form id="statusForm" method="post" action="" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="update_status">
    <input type="hidden" name="user_id" id="statusUserId">
    <input type="hidden" name="new_status" id="statusValue">
</form>
<form id="roleForm" method="post" action="" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="change_role">
    <input type="hidden" name="user_id" id="roleUserId">
    <input type="hidden" name="new_role_id" id="roleValue">
</form>
<form id="passwordForm" method="post" action="" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="reset_password">
    <input type="hidden" name="user_id" id="passwordUserId">
    <input type="hidden" name="new_password" id="passwordValue">
</form>

<script>
function userAction(sel, userId, userName) {
    var action = sel.value;
    sel.value = '';
    if (!action) return;

    if (action === 'suspend' || action === 'activate') {
        var status = action === 'suspend' ? 'suspended' : 'active';
        if (typeof confirmAction === 'function') {
            confirmAction({
                type: action === 'suspend' ? 'danger' : 'warning',
                title: action === 'suspend' ? 'Suspend ' + userName + '?' : 'Activate ' + userName + '?',
                message: action === 'suspend' ? 'This user will not be able to log in until reactivated.' : 'This user will be able to log in again.',
                confirmText: action === 'suspend' ? 'Suspend' : 'Activate',
                onConfirm: function() {
                    document.getElementById('statusUserId').value = userId;
                    document.getElementById('statusValue').value = status;
                    document.getElementById('statusForm').submit();
                    return true;
                }
            });
        } else {
            document.getElementById('statusUserId').value = userId;
            document.getElementById('statusValue').value = status;
            document.getElementById('statusForm').submit();
        }
    } else if (action === 'role') {
        var roleId = prompt('Enter role ID (1=ADMIN, 2=SELLER, 3=USER):');
        if (roleId) {
            document.getElementById('roleUserId').value = userId;
            document.getElementById('roleValue').value = roleId;
            document.getElementById('roleForm').submit();
        }
    } else if (action === 'password') {
        var pwd = prompt('Enter new password (min 8 characters):');
        if (pwd && pwd.length >= 8) {
            document.getElementById('passwordUserId').value = userId;
            document.getElementById('passwordValue').value = pwd;
            document.getElementById('passwordForm').submit();
        } else if (pwd) {
            alert('Password must be at least 8 characters.');
        }
    }
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
