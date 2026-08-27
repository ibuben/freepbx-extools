<div class="container-fluid">
	<h1><?php echo _('eX Telegram Destinations') ?></h1>
	<div class="fpbx-container">
		<div class="display no-border">
			<div id="toolbar-tgdest">
				<a href="?display=exunity_tgdest&amp;view=form" class="btn btn-primary"><i class="fa fa-plus"></i> <?php echo _('Add') ?></a>
			</div>
			<table data-url="ajax.php?module=exunity&amp;command=gettgdests" data-toolbar="#toolbar-tgdest" data-toggle="table" data-pagination="true" data-search="true" data-cache="false" class="table table-striped">
				<thead>
					<tr>
						<th data-field="description"><?php echo _('Description') ?></th>
						<th data-field="chatid"><?php echo _('Chat ID') ?></th>
						<th data-field="actions"><?php echo _('Actions') ?></th>
					</tr>
				</thead>
			</table>
		</div>
	</div>
</div>
