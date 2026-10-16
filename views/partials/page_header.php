<?php
// ============================================================
// views/partials/page_header.php
// Shared page heading: "Previous Page > Current Page", every
// ancestor clickable. Ported from lakbay-pasig's AdminPageHeader
// (src/components/admin/admin-page-header.tsx): one compact row,
// small muted ancestors that underline on hover, caret separators,
// and the current page at full heading size as the <h1>.
//
// One component for every layout, so the trail looks and behaves
// the same for students, staff and admin. Rendered by
// views/layouts/app.php; a page only has to set:
//
//   $pageBreadcrumb = [
//       ['label' => 'Exam Slots', 'to' => '/staff/exam/slots'],
//   ];
//
// `to` is an app path (it goes through url()). The current page is
// never listed: it is the title ($pageHeading / $pageTitle).
// Without $pageBreadcrumb the heading renders exactly as before.
//
// page_header() args:
//   $title      current page name, rendered as the <h1>
//   $breadcrumb ancestors, left to right, each ['label' =>, 'to' =>]
//   $actions    raw HTML for the right edge of the row (callers pass
//               trusted markup, same as the old $pageActions)
//   $variant    'staff' (.page-head, also hosts data-page-action
//               buttons) or 'student' (.page-header-generated)
// ============================================================

if (!function_exists('page_header')) {
    function page_header(string $title, array $breadcrumb = [], string $actions = '', string $variant = 'staff'): string
    {
        $student = ($variant === 'student');

        $trail = '';
        foreach ($breadcrumb as $crumb) {
            $label = (string)($crumb['label'] ?? '');
            $to    = (string)($crumb['to'] ?? '');
            if ($label === '' || $to === '') continue;
            $trail .= '<span class="page-crumb-item">'
                    . '<a class="page-crumb" href="' . e(url($to)) . '">' . e($label) . '</a>'
                    . icon('caret-right', 14, '', 'class="page-crumb-sep"')
                    . '</span>';
        }

        $h1Class = $student ? 'page-title' : 'page-heading';
        $h1      = '<h1 class="' . $h1Class . ' page-crumb-current"'
                 . ($trail !== '' ? ' aria-current="page"' : '') . '>' . e($title) . '</h1>';

        // No ancestors: plain heading, same markup the layout always had.
        $left = ($trail !== '')
            ? '<nav class="page-crumbs" aria-label="Breadcrumb">' . $trail . $h1 . '</nav>'
            : $h1;

        if ($student) {
            return '<header class="page-header page-header-generated">' . $left
                 . ($actions !== '' ? '<div class="page-header-actions">' . $actions . '</div>' : '')
                 . '</header>';
        }

        return '<div class="page-head">' . $left
             . '<div class="page-head-actions" id="page-head-actions">' . $actions . '</div>'
             . '</div>';
    }
}

// Landing page of the signed-in role, as a breadcrumb entry. Used as the
// parent of pages that have no fixed parent (Settings opens from the account
// menu on every page, so its "previous page" is the role's home).
if (!function_exists('page_home_crumb')) {
    function page_home_crumb(): array
    {
        return match (Auth::role()) {
            ROLE_STAFF   => ['label' => 'Interview Queue', 'to' => '/staff/interviews/queue'],
            ROLE_PROCTOR => ['label' => 'Exam Slots',      'to' => '/staff/exam/slots'],
            ROLE_ADMIN, ROLE_SSO, ROLE_DEAN
                         => ['label' => 'Dashboard',       'to' => '/admin/dashboard'],
            default      => ['label' => 'My Documents',    'to' => '/student/documents'],
        };
    }
}
