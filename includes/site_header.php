<?php
/**
 * Site header: logo centred, primary navigation to its left.
 *
 * Included by the visitor-facing pages (index/review/confirm), after
 * the staging banner so that warning stays at the very top of the
 * viewport. The admin pages under public/admin/ deliberately don't use
 * it -- they have their own compact bar with the page title and a sign
 * out button, and a marketing header there would just push the editor
 * down the page.
 *
 * All three including pages sit at the document root, so a relative
 * asset path works. $siteHeaderBase exists for anything nested deeper:
 * set it to '../' before the include.
 */
$siteHeaderBase = $siteHeaderBase ?? '';
$currentPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
?>
<header class="site-header">
  <a class="site-logo" href="<?= h($siteHeaderBase) ?>index.php">
    <!-- Intrinsic dimensions are declared so the browser reserves the
         right space before the image loads; CSS sets the display size. -->
    <img src="<?= h($siteHeaderBase) ?>assets/logo.png" width="1400" height="556"
         alt="Stand Up for Science">
  </a>

  <nav class="site-nav" aria-label="Main">
    <ul>
      <li>
        <a href="<?= h($siteHeaderBase) ?>index.php"
           <?= $currentPage === 'index.php' ? 'aria-current="page"' : '' ?>>Home</a>
      </li>
      <!-- Placeholder destinations -- point these at the real pages
           when they exist. -->
      <li><a href="#">Blog</a></li>
      <li><a href="#">Contact Us</a></li>
    </ul>
  </nav>
</header>
