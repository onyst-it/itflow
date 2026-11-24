<?php
$owner_initial = [];
if ($client_id) $owner_initial[] = ['value'=>'client:'.$client_id,'label'=>(string)getFieldById('clients',$client_id,'client_name').' — Client','client_id'=>$client_id,'vendor_id'=>0,'contact_id'=>0];
?>
<div class="mb-3">
    <label for="onystContactOwner" class="form-label">Client / Vendor <strong class="text-danger">*</strong></label>
    <select class="form-select" id="onystContactOwner" required data-onyst-party-search="contact" data-initial="<?= escapeHtml(json_encode($owner_initial)) ?>">
        <option value="">- Search Clients or Vendors -</option>
        <?php if ($owner_initial) { ?><option selected value="<?= escapeHtml($owner_initial[0]['value']) ?>"><?= escapeHtml($owner_initial[0]['label']) ?></option><?php } ?>
    </select>
    <input type="hidden" name="client_id" value="<?= $client_id ?>">
    <input type="hidden" name="onyst_vendor_id" value="0">
</div>
