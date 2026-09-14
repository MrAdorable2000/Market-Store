<?php
/**
 * pages/admin/super-admins.php — Admin & Assistant Management
 * --------------------------------------------------------------------
 * Super Admin can:
 *   - View all administrators
 *   - Create new admins
 *   - Appoint Assistant Super Admin (with custom or full permissions)
 *   - Edit assistant permissions
 *   - Suspend/activate admins
 *   - Revoke assistant privileges
 *   - Reset admin passwords
 *
 * SECURITY: require_super_admin() — only SUPER_ADMIN can access.
 * Primary Super Admin is protected from all destructive actions.
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
$allPerms = admin_permission_keys();

// --- Handle POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        flash_set('error', t('errors.invalid_token'));
        redirect(APP_URL . '/pages/admin/super-admins.php');
    }
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create_admin':
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            if (mb_strlen($fullName) < 3) { flash_set('error', 'Name too short.'); break; }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { flash_set('error', 'Invalid email.'); break; }
            if (strlen($password) < 8) { flash_set('error', 'Password must be 8+ characters.'); break; }
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) { flash_set('error', 'Email already exists.'); break; }
            $adminRoleId = $pdo->query("SELECT id FROM roles WHERE name='ADMIN' LIMIT 1")->fetchColumn();
            $hash = password_hash($password, HASH_ALGO, ['cost' => HASH_COST]);
            $pdo->prepare('INSERT INTO users (role_id, full_name, email, password_hash, phone, is_seller, status) VALUES (?, ?, ?, ?, ?, 1, "active")')
                ->execute([$adminRoleId, $fullName, $email, $hash, $phone ?: null]);
            // Grant default admin permissions
            $newId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO admin_permissions (user_id, is_assistant, permissions, granted_by) VALUES (?, 0, ?, ?)')
                ->execute([$newId, json_encode(default_admin_permissions()), $myId]);
            try { $pdo->prepare('INSERT INTO audit_log (user_id, action, target_type, target_id, metadata) VALUES (?, "admin.create", "user", ?, ?)')
                ->execute([$myId, $newId, json_encode(['name'=>$fullName,'email'=>$email])]); } catch (PDOException $e) {}
            flash_set('success', 'Admin created: ' . $fullName);
            break;

        case 'appoint_assistant':
            $userId = (int)($_POST['user_id'] ?? 0);
            $permMode = $_POST['perm_mode'] ?? 'full';
            $customPerms = $_POST['permissions'] ?? [];
            $customPerms = array_values(array_filter($customPerms, fn($p) => isset($allPerms[$p])));
            // Never allow 'admins' or 'settings' permission for assistants (ownership protection)
            $customPerms = array_diff($customPerms, ['admins']);
            $perms = $permMode === 'full' ? array_keys($allPerms) : $customPerms;
            $perms = array_values(array_diff($perms, ['admins'])); // Remove 'admins' — only super admin manages admins
            // Verify target exists and isn't a super admin
            $stmt = $pdo->prepare('SELECT u.id, u.is_primary_super_admin, r.name AS role_name FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE u.id = ?');
            $stmt->execute([$userId]);
            $target = $stmt->fetch();
            if (!$target) { flash_set('error', 'User not found.'); break; }
            if (strtoupper($target['role_name']) === 'SUPER_ADMIN') { flash_set('error', 'Cannot appoint a Super Admin as assistant.'); break; }
            appoint_assistant_admin($userId, $perms, $myId);
            try { $pdo->prepare('INSERT INTO audit_log (user_id, action, target_type, target_id, metadata) VALUES (?, "assistant.appoint", "user", ?, ?)')
                ->execute([$myId, $userId, json_encode(['permissions'=>$perms])]); } catch (PDOException $e) {}
            flash_set('success', 'Assistant Super Admin appointed successfully.');
            break;

        case 'update_permissions':
            $userId = (int)($_POST['user_id'] ?? 0);
            $customPerms = $_POST['permissions'] ?? [];
            $customPerms = array_values(array_filter($customPerms, fn($p) => isset($allPerms[$p])));
            $customPerms = array_diff($customPerms, ['admins']);
            // Verify not super admin
            $stmt = $pdo->prepare('SELECT r.name FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE u.id = ?');
            $stmt->execute([$userId]);
            if (strtoupper($stmt->fetchColumn() ?: '') === 'SUPER_ADMIN') { flash_set('error', 'Cannot modify Super Admin.'); break; }
            $pdo->prepare('UPDATE admin_permissions SET permissions = ?, updated_at = NOW() WHERE user_id = ?')
                ->execute([json_encode(array_values($customPerms)), $userId]);
            try { $pdo->prepare('INSERT INTO audit_log (user_id, action, target_type, target_id, metadata) VALUES (?, "assistant.permissions_changed", "user", ?, ?)')
                ->execute([$myId, $userId, json_encode(['permissions'=>$customPerms])]); } catch (PDOException $e) {}
            flash_set('success', 'Permissions updated.');
            break;

        case 'revoke_assistant':
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId === $myId) { flash_set('error', 'Cannot revoke yourself.'); break; }
            revoke_assistant_admin($userId);
            try { $pdo->prepare('INSERT INTO audit_log (user_id, action, target_type, target_id, metadata) VALUES (?, "assistant.revoke", "user", ?, ?)')
                ->execute([$myId, $userId, json_encode([])]); } catch (PDOException $e) {}
            flash_set('success', 'Assistant privileges revoked.');
            break;

        case 'suspend_admin':
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId === $myId) { flash_set('error', 'Cannot suspend yourself.'); break; }
            $stmt = $pdo->prepare('SELECT u.is_primary_super_admin, r.name FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE u.id = ?');
            $stmt->execute([$userId]);
            $target = $stmt->fetch();
            if ($target && (int)$target['is_primary_super_admin'] === 1) { flash_set('error', 'Cannot suspend the primary Super Admin.'); break; }
            if ($target && strtoupper($target['name']) === 'SUPER_ADMIN') { flash_set('error', 'Cannot suspend a Super Admin.'); break; }
            $pdo->prepare('UPDATE users SET status = "suspended", updated_at = NOW() WHERE id = ?')->execute([$userId]);
            try { $pdo->prepare('INSERT INTO audit_log (user_id, action, target_type, target_id, metadata) VALUES (?, "admin.suspend", "user", ?, ?)')
                ->execute([$myId, $userId, json_encode([])]); } catch (PDOException $e) {}
            flash_set('success', 'Admin suspended.');
            break;

        case 'activate_admin':
            $userId = (int)($_POST['user_id'] ?? 0);
            $pdo->prepare('UPDATE users SET status = "active", updated_at = NOW() WHERE id = ?')->execute([$userId]);
            flash_set('success', 'Admin activated.');
            break;

        case 'delete_admin':
            $userId=(int)($_POST['user_id']??0);
            if($userId===$myId){flash_set('error','You cannot delete your own account.');break;}
            $stmt=$pdo->prepare('SELECT u.id,u.full_name,u.is_primary_super_admin,r.name AS role_name FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE u.id=?');$stmt->execute([$userId]);$target=$stmt->fetch();
            if(!$target){flash_set('error','Administrator not found.');break;}
            if(strtoupper($target['role_name'])!=='ADMIN'){flash_set('error','Only standard Admin accounts can be deleted here.');break;}
            if((int)$target['is_primary_super_admin']===1){flash_set('error','The primary Super Admin is protected.');break;}
            try{permanently_delete_user($pdo,$userId);try{$pdo->prepare('INSERT INTO audit_log (user_id,action,target_type,target_id,metadata) VALUES (?,"admin.delete","user",?,?)')->execute([$myId,$userId,json_encode(['name'=>$target['full_name'],'role'=>'ADMIN'])]);}catch(PDOException $e){}flash_set('success','Admin deleted permanently: '.$target['full_name']);}catch(Throwable $e){flash_set('error','Admin could not be deleted. The database transaction was rolled back.');}
            break;

        case 'reset_password':
            $userId = (int)($_POST['user_id'] ?? 0);
            $newPwd = (string)($_POST['new_password'] ?? '');
            if (strlen($newPwd) < 8) { flash_set('error', 'Password must be 8+ chars.'); break; }
            if ($userId === $myId) { flash_set('error', 'Use profile page for own password.'); break; }
            $stmt = $pdo->prepare('SELECT r.name FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE u.id = ?');
            $stmt->execute([$userId]);
            if (strtoupper($stmt->fetchColumn() ?: '') === 'SUPER_ADMIN') { flash_set('error', 'Cannot reset Super Admin password.'); break; }
            $hash = password_hash($newPwd, HASH_ALGO, ['cost' => HASH_COST]);
            $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')->execute([$hash, $userId]);
            flash_set('success', 'Password reset.');
            break;
    }
    redirect(APP_URL . '/pages/admin/super-admins.php');
}

// --- Load all admins + assistants ---
$stmt = $pdo->query(
    "SELECT u.id, u.full_name, u.email, u.phone, u.status, u.created_at, u.is_primary_super_admin,
            r.name AS role_name,
            ap.is_assistant, ap.permissions, ap.granted_at,
            grantor.full_name AS granted_by_name
       FROM users u
       INNER JOIN roles r ON r.id = u.role_id
       LEFT JOIN admin_permissions ap ON ap.user_id = u.id
       LEFT JOIN users grantor ON grantor.id = ap.granted_by
      WHERE r.name IN ('ADMIN', 'SUPER_ADMIN')
      ORDER BY u.is_primary_super_admin DESC, r.name DESC, u.created_at ASC"
);
$admins = $stmt->fetchAll();

// Load eligible users for appointment (non-admin, active)
$eligible = $pdo->query(
    "SELECT u.id, u.full_name, u.email, r.name AS role_name, u.status
       FROM users u INNER JOIN roles r ON r.id = u.role_id
      WHERE r.name NOT IN ('ADMIN', 'SUPER_ADMIN') AND u.status = 'active'
      ORDER BY u.full_name LIMIT 50"
)->fetchAll();

$pageTitle = 'Administrator Management';
$activePage = 'admin';
$adminActivePage = 'super-admins';
$extraCss = '<link rel="stylesheet" href="' . APP_URL . '/assets/css/admin.css">';
$adminPageTitle = 'Administrators & Assistants';
$adminPageSubtitle = 'Manage admin accounts, appoint assistants, control permissions';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="a-shell" id="aShell">
<?php require __DIR__ . '/../../includes/admin_sidebar.php'; ?>
<div class="a-main">
<?php require __DIR__ . '/../../includes/admin_topbar.php'; ?>
<div class="a-content">

<div class="a-hero">
    <div>
        <h2>Administrators & Assistants</h2>
        <p class="a-hero__sub">Manage admin accounts, appoint assistants, control permissions</p>
    </div>
    <div class="a-hero__actions">
        <button type="button" class="a-btn a-btn--accent" onclick="document.getElementById('createAdminModal').style.display='flex'"><?php echo admin_icon('plus'); ?><span>Create Admin</span></button>
        <button type="button" class="a-btn" onclick="document.getElementById('appointModal').style.display='flex'"><?php echo admin_icon('shield'); ?><span>Appoint Assistant</span></button>
    </div>
</div>

<!-- Admin List -->
<section class="a-panel">
    <div class="a-panel__head"><div><h3>All Administrators</h3></div></div>
    <div class="a-table-wrap">
        <table class="a-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Permissions</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($admins as $a):
                    $isSuperAdmin = strtoupper($a['role_name']) === 'SUPER_ADMIN';
                    $isPrimary = (int)$a['is_primary_super_admin'] === 1;
                    $isAssistant = (int)($a['is_assistant'] ?? 0) === 1;
                    $perms = $a['permissions'] ? json_decode($a['permissions'], true) : ($isSuperAdmin ? array_keys($allPerms) : default_admin_permissions());
                    $permCount = count($perms);
                    $isMe = (int)$a['id'] === $myId;
                ?>
                <tr>
                    <td class="cell-primary">
                        <?php echo e($a['full_name']); ?>
                        <?php if ($isPrimary): ?><span class="a-status a-status--active" style="margin-left:4px;">PRIMARY</span><?php endif; ?>
                        <?php if ($isMe): ?><span style="color:var(--a-teal);font-size:10px;">(You)</span><?php endif; ?>
                    </td>
                    <td class="cell-mute"><?php echo e($a['email']); ?></td>
                    <td>
                        <span class="a-status a-status--<?php echo $isSuperAdmin?'active':($isAssistant?'active':'pending'); ?>">
                            <?php echo $isSuperAdmin ? 'SUPER ADMIN' : ($isAssistant ? '⚡ ASSISTANT' : 'ADMIN'); ?>
                        </span>
                    </td>
                    <td><span class="a-status a-status--<?php echo $a['status']==='active'?'active':'suspended'; ?>"><?php echo e($a['status']); ?></span></td>
                    <td class="cell-mute"><?php echo $permCount; ?>/<?php echo count($allPerms); ?> permissions</td>
                    <td>
                        <?php if (!$isSuperAdmin && !$isMe): ?>
                        <select onchange="adminAction(this, <?php echo (int)$a['id']; ?>, '<?php echo e($a['full_name']); ?>', <?php echo $isAssistant ? 'true' : 'false'; ?>)" style="padding:5px 8px;border:1px solid var(--a-line);border-radius:6px;font-size:11px;">
                            <option value="">Actions...</option>
                            <?php if ($a['status'] === 'active'): ?>
                            <option value="suspend">Suspend</option>
                            <?php else: ?>
                            <option value="activate">Activate</option>
                            <?php endif; ?>
                            <?php if ($isAssistant): ?>
                            <option value="edit_perms">Edit Permissions</option>
                            <option value="revoke">Revoke Assistant</option>
                            <?php else: ?>
                            <option value="appoint">Appoint as Assistant</option>
                            <?php endif; ?>
                            <option value="password">Reset Password</option>
                            <option value="delete_admin">Delete Admin Permanently</option>
                        </select>
                        <?php else: ?>
                        <span style="color:var(--a-muted);font-size:11px;">Protected</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

</div></div></div>

<!-- Create Admin Modal -->
<div id="createAdminModal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.5);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)this.style.display='none'">
    <div style="max-width:440px;width:100%;background:#fff;border-radius:16px;padding:28px;box-shadow:0 24px 64px rgba(0,0,0,.2);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
            <h3 style="margin:0;font-size:17px;">Create New Admin</h3>
            <button type="button" onclick="document.getElementById('createAdminModal').style.display='none'" style="background:none;border:0;font-size:20px;cursor:pointer;">×</button>
        </div>
        <form method="post" action="">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create_admin">
            <div style="margin-bottom:12px;"><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Full Name</label><input type="text" name="full_name" required style="width:100%;padding:9px 12px;border:1px solid #e7ebec;border-radius:8px;font-size:13px;"></div>
            <div style="margin-bottom:12px;"><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Email</label><input type="email" name="email" required style="width:100%;padding:9px 12px;border:1px solid #e7ebec;border-radius:8px;font-size:13px;"></div>
            <div style="margin-bottom:12px;"><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Phone (optional)</label><input type="text" name="phone" style="width:100%;padding:9px 12px;border:1px solid #e7ebec;border-radius:8px;font-size:13px;"></div>
            <div style="margin-bottom:18px;"><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Password (min 8 chars)</label><input type="password" name="password" required minlength="8" style="width:100%;padding:9px 12px;border:1px solid #e7ebec;border-radius:8px;font-size:13px;"></div>
            <button type="submit" class="a-btn a-btn--primary" style="width:100%;">Create Admin</button>
        </form>
    </div>
</div>

<!-- Appoint Assistant Modal -->
<div id="appointModal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.5);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)this.style.display='none'">
    <div style="max-width:520px;width:100%;background:#fff;border-radius:16px;padding:28px;box-shadow:0 24px 64px rgba(0,0,0,.2);max-height:90vh;overflow-y:auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
            <h3 style="margin:0;font-size:17px;">Appoint Assistant Super Admin</h3>
            <button type="button" onclick="document.getElementById('appointModal').style.display='none'" style="background:none;border:0;font-size:20px;cursor:pointer;">×</button>
        </div>
        <form method="post" action="" id="appointForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="appoint_assistant">
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Select User to Appoint</label>
                <select name="user_id" required style="width:100%;padding:9px 12px;border:1px solid #e7ebec;border-radius:8px;font-size:13px;">
                    <option value="">Choose a user...</option>
                    <?php foreach ($eligible as $e): ?>
                    <option value="<?php echo (int)$e['id']; ?>"><?php echo e($e['full_name']); ?> — <?php echo e($e['email']); ?> (<?php echo e($e['role_name']); ?>)</option>
                    <?php endforeach; ?>
                    <?php foreach ($admins as $a): if (strtoupper($a['role_name']) !== 'SUPER_ADMIN' && !(int)($a['is_assistant'] ?? 0)): ?>
                    <option value="<?php echo (int)$a['id']; ?>"><?php echo e($a['full_name']); ?> — <?php echo e($a['email']); ?> (ADMIN)</option>
                    <?php endif; endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;">Authority Level</label>
                <label style="display:flex;align-items:center;gap:6px;padding:8px;border:1px solid #e7ebec;border-radius:8px;cursor:pointer;margin-bottom:6px;">
                    <input type="radio" name="perm_mode" value="full" checked onchange="document.getElementById('customPerms').style.display='none'">
                    <span style="font-size:13px;"><strong>Full Assistant Authority</strong> — all operational permissions (cannot manage admins or security)</span>
                </label>
                <label style="display:flex;align-items:center;gap:6px;padding:8px;border:1px solid #e7ebec;border-radius:8px;cursor:pointer;">
                    <input type="radio" name="perm_mode" value="custom" onchange="document.getElementById('customPerms').style.display='block'">
                    <span style="font-size:13px;"><strong>Custom Authority</strong> — select specific permissions</span>
                </label>
            </div>
            <div id="customPerms" style="display:none;margin-bottom:14px;padding:12px;background:#f6f8f9;border-radius:8px;">
                <label style="display:block;font-size:12px;font-weight:600;margin-bottom:8px;">Select Permissions</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                    <?php foreach ($allPerms as $key => $label): if ($key === 'admins') continue; ?>
                    <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;">
                        <input type="checkbox" name="permissions[]" value="<?php echo e($key); ?>" checked> <?php echo e($label); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="submit" class="a-btn a-btn--primary" style="width:100%;">Confirm Appointment</button>
        </form>
    </div>
</div>

<!-- Edit Permissions Modal -->
<div id="editPermsModal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.5);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)this.style.display='none'">
    <div style="max-width:480px;width:100%;background:#fff;border-radius:16px;padding:28px;box-shadow:0 24px 64px rgba(0,0,0,.2);max-height:90vh;overflow-y:auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
            <h3 style="margin:0;font-size:17px;">Edit Assistant Permissions</h3>
            <button type="button" onclick="document.getElementById('editPermsModal').style.display='none'" style="background:none;border:0;font-size:20px;cursor:pointer;">×</button>
        </div>
        <form method="post" action="" id="editPermsForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_permissions">
            <input type="hidden" name="user_id" id="editPermsUserId">
            <div id="editPermsList" style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:18px;"></div>
            <button type="submit" class="a-btn a-btn--primary" style="width:100%;">Save Permissions</button>
        </form>
    </div>
</div>

<!-- Hidden action forms -->
<form id="suspendForm" method="post" action="" style="display:none;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="suspend_admin"><input type="hidden" name="user_id" id="suspendUid"></form>
<form id="activateForm" method="post" action="" style="display:none;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="activate_admin"><input type="hidden" name="user_id" id="activateUid"></form>
<form id="revokeForm" method="post" action="" style="display:none;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="revoke_assistant"><input type="hidden" name="user_id" id="revokeUid"></form>
<form id="deleteAdminForm" method="post" action="" style="display:none;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_admin"><input type="hidden" name="user_id" id="deleteAdminUid"></form>

<script>
var allPerms = <?php echo json_encode($allPerms); ?>;

function adminAction(sel, userId, userName, isAssistant) {
    var action = sel.value;
    sel.value = '';
    if (!action) return;

    if (action === 'suspend' || action === 'activate') {
        if (typeof confirmAction === 'function') {
            confirmAction({
                type: action === 'suspend' ? 'danger' : 'warning',
                title: action === 'suspend' ? 'Suspend ' + userName + '?' : 'Activate ' + userName + '?',
                message: action === 'suspend' ? 'This admin will lose access immediately.' : 'Restore admin access.',
                confirmText: action === 'suspend' ? 'Suspend' : 'Activate',
                onConfirm: function() {
                    var f = action === 'suspend' ? document.getElementById('suspendForm') : document.getElementById('activateForm');
                    var uid = action === 'suspend' ? document.getElementById('suspendUid') : document.getElementById('activateUid');
                    uid.value = userId;
                    f.submit();
                    return true;
                }
            });
        }
    } else if (action === 'revoke') {
        if (typeof confirmAction === 'function') {
            confirmAction({
                type: 'danger',
                title: 'Revoke Assistant Super Admin?',
                message: userName + ' will lose all assistant privileges immediately and revert to a normal admin.',
                confirmText: 'Revoke',
                onConfirm: function() {
                    document.getElementById('revokeUid').value = userId;
                    document.getElementById('revokeForm').submit();
                    return true;
                }
            });
        }
    } else if (action === 'delete_admin') {
        if (typeof confirmAction === 'function') {
            confirmAction({type:'danger',title:'Delete Admin permanently?',message:'This permanently removes '+userName+' and account-owned marketplace data. This cannot be undone.',confirmText:'Delete permanently',onConfirm:function(){document.getElementById('deleteAdminUid').value=userId;document.getElementById('deleteAdminForm').submit();return true;}});
        }
    } else if (action === 'appoint') {
        // Pre-select in the appoint modal
        var sel2 = document.querySelector('#appointForm select[name="user_id"]');
        if (sel2) { sel2.value = userId; }
        document.getElementById('appointModal').style.display = 'flex';
    } else if (action === 'edit_perms') {
        // Build permission checkboxes
        var html = '';
        for (var key in allPerms) {
            if (key === 'admins') continue;
            html += '<label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;">' +
                    '<input type="checkbox" name="permissions[]" value="' + key + '" checked> ' + allPerms[key] + '</label>';
        }
        document.getElementById('editPermsList').innerHTML = html;
        document.getElementById('editPermsUserId').value = userId;
        document.getElementById('editPermsModal').style.display = 'flex';
    } else if (action === 'password') {
        var pwd = prompt('Enter new password for ' + userName + ' (min 8 chars):');
        if (pwd && pwd.length >= 8) {
            // Submit via a form
            var form = document.createElement('form');
            form.method = 'post'; form.action = '';
            form.innerHTML = '<?php echo csrf_field(); ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="' + userId + '"><input type="hidden" name="new_password" value="' + pwd + '">';
            document.body.appendChild(form);
            form.submit();
        } else if (pwd) {
            alert('Password must be at least 8 characters.');
        }
    }
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
