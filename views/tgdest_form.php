<div class="container-fluid">
	<h1><?php echo !empty($item['id']) ? _('eX Edit Telegram destination') : _('eX Add Telegram destination') ?></h1>
	<div class="fpbx-container">
		<div class="display full-border">
			<form class="fpbx-submit" name="exunitytgdest" method="post" action="?display=exunity_tgdest" <?php if (!empty($item['id'])) { ?>data-fpbx-delete="?display=exunity_tgdest&amp;action=delete&amp;id=<?php echo (int) $item['id'] ?>"<?php } ?>>
				<input type="hidden" name="action" value="save">
				<input type="hidden" name="id" value="<?php echo (int) ($item['id'] ?? 0) ?>">
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label class="control-label" for="description"><?php echo _('Description') ?></label></div>
						<div class="col-md-9"><input class="form-control" name="description" id="description" required value="<?php echo htmlentities($item['description'] ?? '') ?>"></div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label class="control-label" for="chatid"><?php echo _('Telegram chat ID') ?></label></div>
						<div class="col-md-9"><input class="form-control" name="chatid" id="chatid" required value="<?php echo htmlentities($item['chatid'] ?? '') ?>"></div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label class="control-label" for="goto0"><?php echo _('Destination after notify') ?></label></div>
						<div class="col-md-9"><?php echo drawselects($item['dest'] ?? '', 0) ?></div>
					</div></div></div></div>
				</div>
			</form>
		</div>
	</div>
</div>
