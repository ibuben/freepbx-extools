<div class="container-fluid">
	<h1><?php echo _('eX Phone Templates') ?></h1>
	<div class="fpbx-container">
		<div class="display no-border">
			<div id="toolbar-templates">
				<a href="?display=exunity_templates&amp;view=form" class="btn btn-primary"><i class="fa fa-plus"></i> <?php echo _('Add') ?></a>
			</div>
			<table data-url="ajax.php?module=exunity&amp;command=gettemplates" data-toolbar="#toolbar-templates" data-toggle="table" data-pagination="true" data-search="true" data-cache="false" class="table table-striped">
				<thead>
					<tr>
						<th data-field="name"><?php echo _('Name') ?></th>
						<th data-field="vendor_name"><?php echo _('Vendor') ?></th>
						<th data-field="models"><?php echo _('Models') ?></th>
						<th data-field="actions"><?php echo _('Actions') ?></th>
					</tr>
				</thead>
			</table>
		</div>
	</div>
</div>
