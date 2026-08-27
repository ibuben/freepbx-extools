<div class="container-fluid">
	<h1><?php echo _('eX Bulk Extensions') ?></h1>
	<div class="fpbx-container">
		<div class="display full-border">
			<form id="exunity-bulk-form">
				<div class="element-container">
					<div class="row">
						<div class="col-md-12">
							<div class="row">
								<div class="form-group">
									<div class="col-md-3"><label class="control-label"><?php echo _('Range') ?></label></div>
									<div class="col-md-4"><input type="number" class="form-control" name="range_from" id="range_from" placeholder="100" required></div>
									<div class="col-md-1 text-center">—</div>
									<div class="col-md-4"><input type="number" class="form-control" name="range_to" id="range_to" placeholder="120" required></div>
								</div>
							</div>
						</div>
					</div>
				</div>
				<div class="element-container">
					<div class="row">
						<div class="col-md-12">
							<div class="row">
								<div class="form-group">
									<div class="col-md-3"><label class="control-label"><?php echo _('Name pattern') ?></label></div>
									<div class="col-md-9"><input type="text" class="form-control" name="name_pattern" value="Agent {ext}">
										<span class="help-block"><?php echo _('{ext} is replaced with the extension number') ?></span>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
				<div class="element-container">
					<div class="row">
						<div class="col-md-12">
							<div class="row">
								<div class="form-group">
									<div class="col-md-3"><label class="control-label"><?php echo _('SIP secret') ?></label></div>
									<div class="col-md-9">
										<select class="form-control" name="secret_mode" id="secret_mode">
											<option value="random"><?php echo _('Random per extension') ?></option>
											<option value="same"><?php echo _('Same secret for all') ?></option>
											<option value="pattern"><?php echo _('Pattern ({ext} allowed)') ?></option>
										</select>
										<input type="text" class="form-control mt-2" name="secret" id="secret_same" placeholder="<?php echo _('Shared secret') ?>" style="display:none;margin-top:8px">
										<input type="text" class="form-control" name="secret_pattern" id="secret_pattern" value="{ext}" style="display:none;margin-top:8px">
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
				<div class="element-container">
					<div class="row">
						<div class="col-md-12">
							<div class="row">
								<div class="form-group">
									<div class="col-md-3"><label class="control-label"><?php echo _('Outbound CID') ?></label></div>
									<div class="col-md-9"><input type="text" class="form-control" name="outboundcid"></div>
								</div>
							</div>
						</div>
					</div>
				</div>
				<div class="element-container">
					<div class="row">
						<div class="col-md-12">
							<div class="row">
								<div class="form-group">
									<div class="col-md-3"><label class="control-label"><?php echo _('Options') ?></label></div>
									<div class="col-md-9">
										<label class="help-block"><input type="checkbox" name="skip_existing" value="1" checked> <?php echo _('Skip extensions that already exist') ?></label>
										<label class="help-block"><input type="checkbox" name="voicemail" value="1"> <?php echo _('Enable voicemail') ?></label>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
				<p>
					<button type="button" class="btn btn-default" id="exunity-bulk-preview"><?php echo _('Preview') ?></button>
					<button type="button" class="btn btn-primary" id="exunity-bulk-create"><?php echo _('Create extensions') ?></button>
				</p>
			</form>
			<div id="exunity-bulk-result"></div>
		</div>
	</div>
</div>
