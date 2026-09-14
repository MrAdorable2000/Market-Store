<?php
/**
 * includes/admin_ui.php
 * --------------------------------------------------------------------
 * Small server-side render helpers shared by all admin pages:
 *
 *   admin_empty_state($icon, $title, $sub)  — polished empty state block
 *   admin_pagination(...)                   — page navigation (mobile friendly)
 *
 * Both use the same translation / icon helpers as the rest of the admin.
 */
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_icons.php';

/** Polished empty state (used wherever a section has no rows). */
function admin_empty_state(string $icon, string $title, string $sub = ''): string
{
    return '<div class="a-empty">'
         . '<div class="a-empty__icon">' . admin_icon($icon) . '</div>'
         . '<h4>' . e($title) . '</h4>'
         . ($sub !== '' ? '<p>' . e($sub) . '</p>' : '')
         . '</div>';
}

/**
 * Render a pagination bar.
 *
 * @param int    $total       total row count
 * @param int    $perPage     rows per page
 * @param int    $page        current 1-based page
 * @param string $baseUrl     e.g. APP_URL . '/pages/admin/users.php'
 * @param array  $keepParams  GET params to preserve (e.g. ['q' => 'john', 'role' => 'SELLER'])
 */
function admin_pagination(int $total, int $perPage, int $page, string $baseUrl, array $keepParams = []): string
{
    $pages = (int) ceil($total / max(1, $perPage));
    if ($pages <= 1) return '';

    $page = max(1, min($pages, $page));
    $from = ($page - 1) * $perPage + 1;
    $to   = min($total, $page * $perPage);

    $url = function (int $p) use ($baseUrl, $keepParams): string {
        $params = array_merge($keepParams, ['page' => $p]);
        return $baseUrl . '?' . http_build_query($params);
    };

    $html = '<div class="a-pager">'
          . '<span class="a-pager__info">' . e(t('admin.showing_range', [
                'from' => number_format($from),
                'to'   => number_format($to),
                'total'=> number_format($total),
            ])) . '</span>'
          . '<div class="a-pager__pages">';

    // Prev
    $html .= $page > 1
        ? '<a class="a-page" href="' . e($url($page - 1)) . '" aria-label="Previous">' . admin_icon('chevron-l') . '</a>'
        : '<span class="a-page" disabled>' . admin_icon('chevron-l') . '</span>';

    // Page numbers with gaps
    $items = [];
    for ($i = 1; $i <= $pages; $i++) {
        if ($i === 1 || $i === $pages || abs($i - $page) <= 1) $items[] = $i;
        elseif (end($items) !== '…') $items[] = '…';
    }
    foreach ($items as $it) {
        if ($it === '…') { $html .= '<span class="a-page is-gap">…</span>'; continue; }
        $html .= $it === $page
            ? '<span class="a-page is-active">' . $it . '</span>'
            : '<a class="a-page" href="' . e($url((int) $it)) . '">' . $it . '</a>';
    }

    // Next
    $html .= $page < $pages
        ? '<a class="a-page" href="' . e($url($page + 1)) . '" aria-label="Next">' . admin_icon('chevron-r') . '</a>'
        : '<span class="a-page" disabled>' . admin_icon('chevron-r') . '</span>';

    $html .= '</div></div>';
    return $html;
}
