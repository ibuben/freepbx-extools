<input type="hidden" name="exunity_sticky_present" value="1">
<div class="element-container">
	<div class="row">
		<div class="col-md-12">
			<div class="row">
				<div class="form-group">
					<div class="col-md-3">
						<label class="control-label" for="exunity_sticky_agent"><?php echo _('Sticky last agent') ?></label>
						<i class="fa fa-question-circle fpbx-help-icon" data-for="exunity_sticky_agent"></i>
					</div>
					<div class="col-md-9">
						<select class="form-control" id="exunity_sticky_agent" name="exunity_sticky_agent">
							<option value="no" <?php echo empty($enabled) ? 'selected' : '' ?>><?php echo _('Disabled') ?></option>
							<option value="yes" <?php echo !empty($enabled) ? 'selected' : '' ?>><?php echo _('Enabled') ?></option>
						</select>
					</div>
				</div>
			</div>
		</div>
	</div>
	<div class="row">
		<div class="col-md-12">
			<span id="exunity_sticky_agent-help" class="help-block fpbx-help-block">
				<?php echo _('If this caller already talked to an agent of this queue (inbound answer or outbound from the agent), the next inbound call to this queue rings that agent first. Busy, reject, timeout, or offline → the call continues to the queue as usual. Apply Config after saving.') ?>
			</span>
		</div>
	</div>
</div>
