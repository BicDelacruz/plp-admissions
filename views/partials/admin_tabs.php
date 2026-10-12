<?php
// A6: Shared admin settings tab navigation
$adminTabs = [
    '/admin/settings'     => 'Branding & Password',
    '/admin/school-year'  => 'School Year',
    '/admin/courses'      => 'Courses & Thresholds',
    '/admin/users'        => 'User Management',
];
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath    = parse_url(BASE_URL, PHP_URL_PATH);
$relPath     = $basePath ? substr($currentPath, strlen($basePath)) : $currentPath;
?>
<nav class="segmented-control" aria-label="Admin settings" style="margin-bottom:var(--space-6);flex-wrap:wrap">
    <?php foreach ($adminTabs as $tabPath => $tabLabel):
        $isActive = ($relPath === $tabPath);
    ?>
        <a href="<?= url($tabPath) ?>" class="<?= $isActive ? 'is-active' : '' ?>" <?= $isActive ? 'aria-current="page"' : '' ?>><?= e($tabLabel) ?></a>
    <?php endforeach; ?>
</nav>
