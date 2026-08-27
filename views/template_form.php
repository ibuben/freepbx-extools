<div class="container-fluid">
	<h1><?php echo !empty($item['id']) ? _('eX Edit template') : _('eX Add template') ?></h1>
	<div class="fpbx-container">
		<div class="display full-border">
			<form class="fpbx-submit" method="post" action="?display=exunity_templates" <?php if (!empty($item['id'])) { ?>data-fpbx-delete="?display=exunity_templates&amp;action=delete&amp;id=<?php echo (int) $item['id'] ?>"<?php } ?>>
				<input type="hidden" name="action" value="save">
				<input type="hidden" name="id" value="<?php echo (int) ($item['id'] ?? 0) ?>">
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label class="control-label" for="name"><?php echo _('Name') ?></label></div>
						<div class="col-md-9"><input class="form-control" name="name" id="name" required value="<?php echo htmlentities($item['name'] ?? '') ?>"></div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label class="control-label" for="vendor_id"><?php echo _('Vendor') ?></label></div>
						<div class="col-md-9">
							<select class="form-control" name="vendor_id" id="vendor_id">
								<option value=""><?php echo _('Any') ?></option>
								<?php foreach ($vendors as $v) { ?>
									<option value="<?php echo (int) $v['id'] ?>" <?php echo ((int) ($item['vendor_id'] ?? 0) === (int) $v['id']) ? 'selected' : '' ?>><?php echo htmlentities($v['name']) ?></option>
								<?php } ?>
							</select>
						</div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label class="control-label" for="models"><?php echo _('Models') ?></label></div>
						<div class="col-md-9"><input class="form-control" name="models" id="models" value="<?php echo htmlentities(is_array($item['models'] ?? null) ? implode(',', $item['models']) : ($item['models'] ?? '')) ?>">
							<span class="help-block"><?php echo _('JSON array or comma-separated model names') ?></span>
						</div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label class="control-label" for="content_type"><?php echo _('Content type') ?></label></div>
						<div class="col-md-9"><input class="form-control" name="content_type" id="content_type" value="<?php echo htmlentities($item['content_type'] ?? 'text/plain; charset=utf-8') ?>"></div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label class="control-label" for="is_default"><?php echo _('Default for vendor') ?></label></div>
						<div class="col-md-9"><input type="checkbox" name="is_default" value="1" <?php echo !empty($item['is_default']) ? 'checked' : '' ?>></div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label class="control-label" for="config_body"><?php echo _('Template body') ?></label></div>
						<div class="col-md-9"><textarea class="form-control" rows="18" name="config_body" id="config_body"><?php echo htmlentities($item['config_body'] ?? '') ?></textarea>
							<span class="help-block"><?php echo _('Use {{sip_extension}} {{sip_password}} {{sip_server}} {{sip_port}} {{display_name}} {{phonebook_yealink_url}} {{phonebook_grandstream_url}} {{phonebook_fanvil_url}} {{phonebook_microsip_url}} {{phonebook_name}} and other variables') ?></span>
						</div>
					</div></div></div></div>
				</div>
			</form>
		</div>
	</div>
</div>
