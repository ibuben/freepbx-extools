<div class="container-fluid">
	<h1><?php echo _('eX Phones') ?></h1>
	<div class="fpbx-container">
		<div class="display no-border">
			<div id="toolbar-phones">
				<a href="?display=exunity_phones&amp;action=autoassign" class="btn btn-primary" onclick="return confirm('<?php echo _('Assign free extensions to phones that have no extension yet?') ?>');"><?php echo _('Auto-assign free extensions') ?></a>
			</div>
			<table id="exunity-phones" data-url="ajax.php?module=exunity&amp;command=getphones" data-toolbar="#toolbar-phones" data-toggle="table" data-pagination="true" data-search="true" data-cache="false" class="table table-striped">
				<thead>
					<tr>
						<th data-field="mac_address"><?php echo _('MAC') ?></th>
						<th data-field="ip_address"><?php echo _('IP') ?></th>
						<th data-field="vendor_name"><?php echo _('Vendor') ?></th>
						<th data-field="model"><?php echo _('Model') ?></th>
						<th data-field="extension"><?php echo _('Extension') ?></th>
						<th data-field="sip_status"><?php echo _('PJSIP') ?></th>
						<th data-field="last_seen"><?php echo _('Last provision') ?></th>
						<th data-field="provision_status"><?php echo _('Provision status') ?></th>
						<th data-field="actions"><?php echo _('Actions') ?></th>
					</tr>
				</thead>
			</table>
			<p class="help-block"><?php echo _('Phones appear here after they fetch a config from the provisioning URL.') ?></p>
		</div>
	</div>
</div>
