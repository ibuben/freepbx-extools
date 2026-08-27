<div class="container-fluid">
	<h1><?php echo _('eX Edit phone') ?></h1>
	<div class="fpbx-container">
		<div class="display full-border">
			<?php if (empty($item)) { ?>
				<p><?php echo _('Phone not found') ?></p>
			<?php } else { ?>
			<form class="fpbx-submit" method="post" action="?display=exunity_phones" data-fpbx-delete="?display=exunity_phones&amp;action=delete&amp;id=<?php echo (int) $item['id'] ?>">
				<input type="hidden" name="action" value="save">
				<input type="hidden" name="id" value="<?php echo (int) $item['id'] ?>">
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label class="control-label"><?php echo _('MAC') ?></label></div>
						<div class="col-md-9"><p class="form-control-static"><?php echo htmlentities($item['mac_address']) ?></p></div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label class="control-label"><?php echo _('IP') ?></label></div>
						<div class="col-md-9"><p class="form-control-static"><?php echo htmlentities($item['ip_address']) ?> / <?php echo htmlentities($item['model'] ?? '') ?></p></div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label class="control-label" for="extension"><?php echo _('Extension') ?></label></div>
						<div class="col-md-9">
							<select class="form-control" name="extension" id="extension">
								<?php foreach ($extensions as $val => $label) { ?>
									<option value="<?php echo htmlentities((string) $val) ?>" <?php echo ((string) ($item['extension'] ?? '') === (string) $val) ? 'selected' : '' ?>><?php echo htmlentities($label) ?></option>
								<?php } ?>
							</select>
						</div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label class="control-label" for="template_id"><?php echo _('Template') ?></label></div>
						<div class="col-md-9">
							<select class="form-control" name="template_id" id="template_id">
								<option value=""><?php echo _('Auto (by model)') ?></option>
								<?php foreach ($templates as $t) { ?>
									<option value="<?php echo (int) $t['id'] ?>" <?php echo ((int) ($item['template_id'] ?? 0) === (int) $t['id']) ? 'selected' : '' ?>><?php echo htmlentities($t['name']) ?></option>
								<?php } ?>
							</select>
						</div>
					</div></div></div></div>
				</div>
			</form>
			<?php } ?>
		</div>
	</div>
</div>
