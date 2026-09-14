<?php
/**
 * includes/admin_icons.php
 * --------------------------------------------------------------------
 * Central line-icon library for the IsokoRyacu admin area.
 * One consistent visual language: 24x24 viewBox, stroke = currentColor,
 * stroke-width 1.8, round caps/joins. Used by the sidebar, topbar,
 * KPI cards, tables and empty states.
 *
 *   echo admin_icon('users');           // <svg ...>...</svg>
 *   echo admin_icon('users', 'danger'); // class variant hook
 */
declare(strict_types=1);

if (!function_exists('admin_icon')) {
    function admin_icon(string $name): string
    {
        static $icons = null;
        if ($icons === null) {
            $icons = [
                // navigation
                'dashboard'   => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
                'listings'    => '<rect x="3" y="3" width="18" height="18" rx="2.5"/><path d="M3 9h18M9 9v12"/>',
                'categories'  => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
                'users'       => '<circle cx="9" cy="8" r="3.6"/><path d="M3.5 20a5.5 5.5 0 0 1 11 0"/><path d="M15.5 5.2a3.6 3.6 0 0 1 0 6.9"/><path d="M17.5 14.6a5.5 5.5 0 0 1 3 4.9"/>',
                'rentals'     => '<rect x="2" y="7" width="20" height="13" rx="2"/><path d="M2 11h20M7 7V5.5A1.5 1.5 0 0 1 8.5 4h7A1.5 1.5 0 0 1 17 5.5V7"/><path d="M8.5 13.5h7"/>',
                'reviews'     => '<path d="M12 3.5 14.3 8l5 .7-3.6 3.5.9 5-4.6-2.4-4.6 2.4.9-5L4.7 8.7l5-.7z" stroke-linejoin="round"/>',
                'reports'     => '<path d="M12 3 2.5 20.5h19z" stroke-linejoin="round"/><path d="M12 9.5v4.5M12 17.2h.01"/>',
                'messages'    => '<path d="M21 14.5a2 2 0 0 1-2 2H7.5L3 20.5V5.5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M7.5 9h9M7.5 12.5h6"/>',
                'analytics'   => '<path d="M3.5 3.5v17h17"/><path d="M7.5 14.5l3.5-3.5 3 3 5.5-6"/><path d="M19.5 8v-2h-2"/>',
                'logs'        => '<path d="M4.5 4.5h15v15h-15z" rx="2"/><path d="M8 9h8M8 12.5h8M8 16h5"/>',
                'settings'    => '<circle cx="12" cy="12" r="3"/><path d="M12 2.8v2.4M12 18.8v2.4M4.2 7.2l2.1 1.2M17.7 15.6l2.1 1.2M4.2 16.8l2.1-1.2M17.7 8.4l2.1-1.2"/>',
                'website'     => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
                'logout'      => '<path d="M9 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3"/><path d="M16 17l5-5-5-5M21 12H9"/>',
                'profile'     => '<circle cx="12" cy="8.5" r="3.6"/><path d="M5 20.5a7 7 0 0 1 14 0"/>',
                // utilities
                'search'      => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.2-4.2" stroke-linecap="round"/>',
                'check'       => '<path d="m4.5 12.5 5 5 10-11" stroke-linecap="round" stroke-linejoin="round"/>',
                'check-circle'=> '<circle cx="12" cy="12" r="9"/><path d="m8 12.3 2.7 2.7L16.5 9"/>',
                'close-x'     => '<path d="M18 6 6 18M6 6l12 12" stroke-linecap="round"/>',
                'menu'        => '<path d="M3 12h18M3 6h18M3 18h18" stroke-linecap="round"/>',
                'chevron-l'   => '<path d="m14 6-6 6 6 6" stroke-linecap="round" stroke-linejoin="round"/>',
                'chevron-r'   => '<path d="m10 6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/>',
                'bell'        => '<path d="M18 9a6 6 0 1 0-12 0c0 5-2 6-2 6h16s-2-1-2-6"/><path d="M10.3 19a2 2 0 0 0 3.4 0"/>',
                'plus'        => '<path d="M12 5v14M5 12h14" stroke-linecap="round"/>',
                'eye'         => '<path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.8"/>',
                'edit'        => '<path d="M4 20h4L19.5 8.5a2.1 2.1 0 0 0-3-3L5 17z"/><path d="m13.5 6.5 3 3"/>',
                'trash'       => '<path d="M4 7h16M9 7V5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 5v2"/><path d="M6.5 7l1 13h9l1-13"/><path d="M10 11v5M14 11v5"/>',
                'trend-up'    => '<path d="m3.5 16 5-5 3.5 3.5 6-6.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M13 8h5v5"/>',
                'trend-down'  => '<path d="m3.5 8 5 5 3.5-3.5 6 6.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M13 16h5v-5"/>',
                'trend-flat'  => '<path d="M3.5 12h17" stroke-linecap="round"/>',
                'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
                'flag'        => '<path d="M5 21V4"/><path d="M5 4.5h11l-2.5 4L16 12.5H5"/>',
                'dollar'      => '<path d="M12 3v18"/><path d="M16.5 7.5A3.5 3.5 0 0 0 13 5h-1.8a3.2 3.2 0 0 0 0 6.4h2a3.2 3.2 0 0 1 0 6.4H11a3.5 3.5 0 0 1-3.5-2.3"/>',
                'heart'       => '<path d="M12 20s-7.5-4.7-9.3-9.3C1.5 7.6 3.9 5 6.8 5c1.9 0 3.4 1 4.2 2.4.8-1.4 2.3-2.4 4.2-2.4 2.9 0 5.3 2.6 4.1 5.7C17.5 15.3 12 20 12 20z" stroke-linejoin="round"/>',
                'inbox'       => '<path d="M3.5 12 6 4.5h12L20.5 12v7a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 19z"/><path d="M3.5 12h4.5l1.5 3h5l1.5-3h4.5"/>',
                'mail'        => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>',
                'user-check'  => '<circle cx="9" cy="8" r="3.6"/><path d="M3.5 20a5.5 5.5 0 0 1 11 0"/><path d="m15.5 10.5 2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/>',
                'user-x'      => '<circle cx="9" cy="8" r="3.6"/><path d="M3.5 20a5.5 5.5 0 0 1 11 0"/><path d="m16 9 5 5M21 9l-5 5" stroke-linecap="round"/>',
                'star'        => '<path d="M12 3.5 14.3 8l5 .7-3.6 3.5.9 5-4.6-2.4-4.6 2.4.9-5L4.7 8.7l5-.7z" stroke-linejoin="round"/>',
                'image'       => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="9.5" r="1.8"/><path d="m3.5 17.5 5-4.5 3.5 3 3.5-3.5 5 4.5"/>',
                'info'        => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
                'shield'      => '<path d="M12 3l7.5 3v5.5c0 4.6-3.2 8-7.5 9.5-4.3-1.5-7.5-4.9-7.5-9.5V6z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
                'globe'       => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
                'calendar'    => '<rect x="3.5" y="5" width="17" height="16" rx="2"/><path d="M8 3v4M16 3v4M3.5 10h17"/>',
                'refresh'     => '<path d="M20 11a8 8 0 0 0-14.9-3M4 13a8 8 0 0 0 14.9 3"/><path d="M5.1 8v4h4M18.9 16v-4h-4" stroke-linecap="round"/>',
            ];
        }
        $path = $icons[$name] ?? $icons['info'];
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
             . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
    }
}
