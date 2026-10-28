<?php
/**
 * Pass Fees - the ONE place where the bus-pass price is configured.
 * Stored in the existing `settings` table, key `monthly_bus_pass_fee`.
 */
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_role('admin');

if (is_post()) {
    require_csrf();
    $raw = input('monthly_bus_pass_fee');
    if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $raw) || (float) $raw <= 0 || (float) $raw > 100000) {
        flash('danger', 'Enter a fee between ₹0.01 and ₹1,00,000 (up to 2 decimals).');
        redirect('admin/fees.php');
    }
    $old = monthly_pass_fee();
    $new = round((float) $raw, 2);
    set_setting('monthly_bus_pass_fee', rtrim(rtrim(number_format($new, 2, '.', ''), '0'), '.'));
    audit_log('monthly_fee_changed', 'settings', null, ['from' => $old, 'to' => $new]);
    flash('success', 'Monthly bus-pass fee is now ' . money($new) . '. New applications will use this amount.');
    redirect('admin/fees.php');
}

$fee = monthly_pass_fee();
page_header('Bus-Pass Fees', 'fees.php');
?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-header">Monthly bus pass</div><div class="card-body">
      <?php if ($fee === null): ?><div class="alert alert-danger small">No fee is configured – students cannot apply until you save one.</div><?php endif; ?>
      <form method="post" class="needs-validation" novalidate>
        <?= csrf_field() ?>
        <label class="form-label" for="monthly_bus_pass_fee">Monthly Bus Pass Fee (₹)</label>
        <div class="input-group mb-2" style="max-width:280px">
          <span class="input-group-text">₹</span>
          <input class="form-control" type="number" id="monthly_bus_pass_fee" name="monthly_bus_pass_fee" required min="0.01" max="100000" step="0.01" value="<?= e($fee !== null ? rtrim(rtrim(number_format($fee, 2, '.', ''), '0'), '.') : '') ?>">
        </div>
        <div class="form-text mb-3">Pass duration is fixed at <strong>1 month</strong>. New applications use this amount on the application page, payment page and payment record.</div>
        <button class="btn btn-primary">Save fee</button>
      </form>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-body small">
      <p class="mb-2"><strong>Current price:</strong> 1 Month — <?= $fee !== null ? e(money($fee)) : '<span class="text-danger">not set</span>' ?></p>
      <p class="mb-0">New applications use the price saved here. Applications that already exist keep the amount recorded when they were created.</p>
    </div></div>
  </div>
</div>
<?php page_footer();
