<?php if (current_app_mode() === 'staging'): ?>
<div class="staging-banner">
  Staging Mode &mdash; real recipients are shown below, but every message is redirected to internal test addresses and will never reach them.
</div>
<?php endif; ?>
