<div class="container-fluid">
	<h1><?php echo _('eX Bulk Edit') ?></h1>
	<div class="fpbx-container">
		<div class="display full-border">
			<p class="help-block"><?php echo _('Select existing extensions, then tick only the fields you want to change. Unticked fields and empty text values are left as they are.') ?></p>
			<form id="exunity-bulkedit-form">
				<div class="element-container">
					<div class="row">
						<div class="col-md-12">
							<div class="row">
								<div class="form-group">
									<div class="col-md-3"><label class="control-label" for="bulk_extens"><?php echo _('Extensions') ?></label></div>
									<div class="col-md-9">
										<div class="form-inline" style="margin-bottom:8px">
											<input type="number" class="form-control" id="be_range_from" placeholder="100" style="width:120px">
											<span style="padding:0 8px">—</span>
											<input type="number" class="form-control" id="be_range_to" placeholder="120" style="width:120px">
											<button type="button" class="btn btn-default" id="be-add-range"><?php echo _('Add range to selection') ?></button>
										</div>
										<div class="input-group">
											<textarea id="bulk_extens" class="form-control" name="extensions" rows="4" placeholder="<?php echo _('One extension per line') ?>"></textarea>
											<span class="input-group-addon" style="display:none">
												<select id="qsagents1" class="form-control" data-for="bulk_extens">
													<option value=""></option>
													<?php foreach ($users as $u) { ?>
														<option value="<?php echo htmlentities($u[0]) ?>"><?php echo htmlentities($u[0] . ' (' . ($u[1] ?? '') . ')') ?></option>
													<?php } ?>
												</select>
											</span>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>

				<hr>
				<p><strong><?php echo _('Fields to apply') ?></strong></p>

				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3">
							<label><input type="checkbox" name="apply_secret" value="1"> <?php echo _('SIP secret') ?></label>
						</div>
						<div class="col-md-9">
							<select class="form-control" name="secret_mode" id="be_secret_mode">
								<option value="random"><?php echo _('Random per extension') ?></option>
								<option value="same"><?php echo _('Same secret for all') ?></option>
								<option value="pattern"><?php echo _('Pattern ({ext} allowed)') ?></option>
							</select>
							<input type="text" class="form-control" name="secret" id="be_secret_same" placeholder="<?php echo _('Shared secret') ?>" style="display:none;margin-top:8px">
							<input type="text" class="form-control" name="secret_pattern" id="be_secret_pattern" value="{ext}" style="display:none;margin-top:8px">
						</div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label><input type="checkbox" name="apply_outboundcid" value="1"> <?php echo _('Outbound CID') ?></label></div>
						<div class="col-md-9"><input type="text" class="form-control" name="outboundcid"></div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label><input type="checkbox" name="apply_emergency_cid" value="1"> <?php echo _('Emergency CID') ?></label></div>
						<div class="col-md-9"><input type="text" class="form-control" name="emergency_cid"></div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label><input type="checkbox" name="apply_ringtimer" value="1"> <?php echo _('Ring timer (sec)') ?></label></div>
						<div class="col-md-9"><input type="number" class="form-control" name="ringtimer" min="0" value="0">
							<span class="help-block"><?php echo _('0 = default') ?></span>
						</div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label><input type="checkbox" name="apply_callwaiting" value="1"> <?php echo _('Call waiting') ?></label></div>
						<div class="col-md-9">
							<select class="form-control" name="callwaiting">
								<option value="enabled"><?php echo _('Enabled') ?></option>
								<option value="disabled"><?php echo _('Disabled') ?></option>
							</select>
						</div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label><input type="checkbox" name="apply_recording" value="1"> <?php echo _('Call recording') ?></label></div>
						<div class="col-md-9">
							<select class="form-control" name="recording">
								<option value="dontcare"><?php echo _("Don't Care") ?></option>
								<option value="always"><?php echo _('Always') ?></option>
								<option value="never"><?php echo _('Never') ?></option>
							</select>
							<span class="help-block"><?php echo _('Applied to inbound and outbound, internal and external') ?></span>
						</div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label><input type="checkbox" name="apply_max_contacts" value="1"> <?php echo _('Max contacts') ?></label></div>
						<div class="col-md-9"><input type="number" class="form-control" name="max_contacts" min="1" max="100" value="1"></div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label><input type="checkbox" name="apply_dtmfmode" value="1"> <?php echo _('DTMF mode') ?></label></div>
						<div class="col-md-9">
							<select class="form-control" name="dtmfmode">
								<option value="rfc4733">rfc4733</option>
								<option value="inband">inband</option>
								<option value="info">info</option>
								<option value="auto">auto</option>
							</select>
						</div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label><input type="checkbox" name="apply_transport" value="1"> <?php echo _('SIP transport') ?></label></div>
						<div class="col-md-9"><input type="text" class="form-control" name="transport" placeholder="0.0.0.0-udp">
							<span class="help-block"><?php echo _('PJSIP transport name, e.g. 0.0.0.0-udp') ?></span>
						</div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label><input type="checkbox" name="apply_qualifyfreq" value="1"> <?php echo _('Qualify frequency') ?></label></div>
						<div class="col-md-9"><input type="number" class="form-control" name="qualifyfreq" min="0" value="60"></div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label><input type="checkbox" name="apply_voicemail" value="1"> <?php echo _('Voicemail') ?></label></div>
						<div class="col-md-9">
							<select class="form-control" name="voicemail">
								<option value="yes"><?php echo _('Enable') ?></option>
								<option value="no"><?php echo _('Disable') ?></option>
							</select>
						</div>
					</div></div></div></div>
				</div>
				<div class="element-container">
					<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
						<div class="col-md-3"><label><input type="checkbox" name="apply_webrtc" value="1"> <?php echo _('WebRTC / WSS') ?></label></div>
						<div class="col-md-9">
							<select class="form-control" name="webrtc">
								<option value="yes"><?php echo _('Enable (WebRTC over WSS)') ?></option>
								<option value="no"><?php echo _('Disable (restore SIP phone defaults)') ?></option>
							</select>
							<select class="form-control" name="webrtc_transport" style="margin-top:8px">
								<?php if (empty($default_wss)) { ?>
									<option value=""><?php echo _('No WSS transport (enable it in SIP Settings)') ?></option>
								<?php } ?>
								<?php foreach ($transports as $t) { ?>
									<option value="<?php echo htmlentities($t) ?>" <?php echo (($default_wss ?? '') === $t) ? 'selected' : '' ?>><?php echo htmlentities($t) ?></option>
								<?php } ?>
							</select>
							<span class="help-block"><?php echo _('Sets webrtc=yes, AVPF, DTLS, ICE, rtcp_mux, bundle, direct_media=no, force_rport, rewrite_contact, rtp_symmetric, DTMF rfc4733, and the selected WSS transport. Asterisk applies dtls_auto_generate_cert when webrtc=yes. Regular UDP phones on the same extension will stop working. Enable WSS in Asterisk SIP Settings first — FreePBX names the transport 0.0.0.0-wss, not transport-wss.') ?></span>
							<?php if (empty($default_wss)) { ?>
								<div class="alert alert-warning" style="margin-top:8px"><?php echo _('WSS is not enabled on this PBX yet. Turn on WSS in Settings → Asterisk SIP Settings, Apply Config, then return here.') ?></div>
							<?php } ?>
						</div>
					</div></div></div></div>
				</div>

				<div class="element-container">
					<button type="button" class="btn btn-default" id="exunity-bulkedit-preview"><?php echo _('Preview') ?></button>
					<button type="button" class="btn btn-primary" id="exunity-bulkedit-apply"><?php echo _('Apply changes') ?></button>
				</div>
				<div id="exunity-bulkedit-result"></div>
			</form>
		</div>
	</div>
</div>
