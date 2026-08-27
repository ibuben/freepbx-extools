<style>
#exunity-settings-tabs + .tab-content { padding-top: 12px; }
#exunity-settings-tabs .nav-tabs { margin-bottom: 0; }
</style>
<div class="container-fluid">
	<h1><?php echo _('eX Settings') ?></h1>
	<div class="fpbx-container">
		<form class="fpbx-submit" name="exunitysettings" method="post" action="?display=exunity">
			<input type="hidden" name="action" value="savesettings">
			<input type="hidden" name="phonebook_groups_present" value="1">
			<input type="hidden" name="sticky_queues_present" value="1">
			<div class="display no-border">
				<div class="nav-container" id="exunity-settings-tabs">
					<ul class="nav nav-tabs list" role="tablist">
						<li role="presentation" class="active">
							<a href="#exset-admin" aria-controls="exset-admin" role="tab" data-toggle="tab"><?php echo _('Admin') ?></a>
						</li>
						<li role="presentation">
							<a href="#exset-telegram" aria-controls="exset-telegram" role="tab" data-toggle="tab"><?php echo _('Telegram') ?></a>
						</li>
						<li role="presentation">
							<a href="#exset-phones" aria-controls="exset-phones" role="tab" data-toggle="tab"><?php echo _('Phones') ?></a>
						</li>
						<li role="presentation">
							<a href="#exset-phonebook" aria-controls="exset-phonebook" role="tab" data-toggle="tab"><?php echo _('Phonebook') ?></a>
						</li>
						<li role="presentation">
							<a href="#exset-recordings" aria-controls="exset-recordings" role="tab" data-toggle="tab"><?php echo _('Recordings') ?></a>
						</li>
						<li role="presentation">
							<a href="#exset-queues" aria-controls="exset-queues" role="tab" data-toggle="tab"><?php echo _('Queues') ?></a>
						</li>
					</ul>
				</div>
				<div class="tab-content display">
					<div id="exset-admin" class="tab-pane active" role="tabpanel">
						<div class="element-container">
							<div class="row">
								<div class="col-md-12">
									<div class="row">
										<div class="form-group">
											<div class="col-md-3"><label class="control-label" for="ui_theme"><?php echo _('Admin UI theme') ?></label></div>
											<div class="col-md-9">
												<select class="form-control" id="ui_theme" name="ui_theme">
													<option value="yes" <?php echo ($settings['ui_theme'] ?? 'yes') === 'yes' ? 'selected' : '' ?>><?php echo _('Enabled (dark theme)') ?></option>
													<option value="no" <?php echo ($settings['ui_theme'] ?? 'yes') === 'no' ? 'selected' : '' ?>><?php echo _('Disabled (stock FreePBX)') ?></option>
												</select>
												<span class="help-block"><?php echo _('Dark theme for the whole FreePBX admin. Reload the page after saving. Turn off to restore the original look.') ?></span>
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
											<div class="col-md-3"><label class="control-label" for="stats_enabled"><?php echo _('Anonymous usage stats') ?></label></div>
											<div class="col-md-9">
												<select class="form-control" id="stats_enabled" name="stats_enabled">
													<option value="yes" <?php echo ($settings['stats_enabled'] ?? 'yes') === 'yes' ? 'selected' : '' ?>><?php echo _('Enabled') ?></option>
													<option value="no" <?php echo ($settings['stats_enabled'] ?? 'yes') === 'no' ? 'selected' : '' ?>><?php echo _('Disabled') ?></option>
												</select>
												<span class="help-block"><?php echo _('Once a month we send this PBX IP address, FreePBX version, and Deployment ID (if Sysadmin has one) to eXUnity LAB. No more information is collected. This helps us see how widely eXTools is used and whether it still works on different FreePBX versions. We would be very grateful if you leave statistics enabled.') ?></span>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
					<div id="exset-telegram" class="tab-pane" role="tabpanel">
						<div class="element-container">
							<div class="row">
								<div class="col-md-12">
									<div class="row">
										<div class="form-group">
											<div class="col-md-3"><label class="control-label" for="tg_token"><?php echo _('Telegram bot token') ?></label></div>
											<div class="col-md-9"><input type="text" class="form-control confidential" id="tg_token" name="tg_token" value="<?php echo htmlentities($settings['tg_token']) ?>"></div>
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
											<div class="col-md-3"><label class="control-label" for="tg_template_ring"><?php echo _('Incoming call template') ?></label></div>
											<div class="col-md-9"><textarea class="form-control" rows="5" id="tg_template_ring" name="tg_template_ring"><?php echo htmlentities($settings['tg_template_ring']) ?></textarea>
												<span class="help-block"><?php echo _('Placeholders: {{callerid}} {{callername}} {{extension}} {{did}} {{time}}. Diversion (forwarded-from): {{diversion}} {{diversion_reason}} {{rdnis}}. Number links: {{callerid_e164}} {{callerid_tg}} {{callerid_tel}} {{did_e164}} {{did_tg}} {{did_tel}} {{diversion_e164}} {{diversion_tg}} {{diversion_tel}}. Wrap optional diversion text in {{if_diversion}} ... {{/if_diversion}}. An icon before a URL in parentheses becomes the link, so 📩 (https://t.me/+{{callerid_e164}}) shows only 📩.') ?></span>
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
											<div class="col-md-3"><label class="control-label" for="tg_template_missed"><?php echo _('Missed call template (optional)') ?></label></div>
											<div class="col-md-9"><textarea class="form-control" rows="4" id="tg_template_missed" name="tg_template_missed"><?php echo htmlentities($settings['tg_template_missed']) ?></textarea>
												<span class="help-block"><?php echo _('Same placeholders and icon links as the incoming call template.') ?></span>
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
											<div class="col-md-3"><label class="control-label" for="tg_vm"><?php echo _('Voicemail to Telegram') ?></label></div>
											<div class="col-md-9">
												<select class="form-control" id="tg_vm" name="tg_vm">
													<option value="yes" <?php echo ($settings['tg_vm'] ?? 'yes') === 'yes' ? 'selected' : '' ?>><?php echo _('Enabled') ?></option>
													<option value="no" <?php echo ($settings['tg_vm'] ?? 'yes') === 'no' ? 'selected' : '' ?>><?php echo _('Disabled') ?></option>
												</select>
												<span class="help-block"><?php echo _('When a caller leaves voicemail for an extension that has Telegram notify enabled, send the recording to that same chat as a voice message. Apply Config after saving. Needs ffmpeg for Telegram voice bubbles (otherwise the WAV file is attached).') ?></span>
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
											<div class="col-md-3"><label class="control-label" for="tg_template_vm"><?php echo _('Voicemail caption') ?></label></div>
											<div class="col-md-9"><textarea class="form-control" rows="4" id="tg_template_vm" name="tg_template_vm"><?php echo htmlentities($settings['tg_template_vm'] ?? '') ?></textarea>
												<span class="help-block"><?php echo _('Caption under the voice message. Extra placeholder: {{duration}}. Same caller/DID/icon links as the incoming call template.') ?></span>
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
											<div class="col-md-3"><label class="control-label" for="tg_country_code"><?php echo _('Default country code') ?></label></div>
											<div class="col-md-9">
												<input type="text" class="form-control" id="tg_country_code" name="tg_country_code" value="<?php echo htmlentities($settings['tg_country_code']) ?>">
												<span class="help-block"><?php echo _('Used to build {{callerid_e164}} / t.me links. Example: 998 turns 123456789 into https://t.me/+998123456789') ?></span>
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
											<div class="col-md-3"><label class="control-label" for="tg_test_chatid"><?php echo _('Test chat ID') ?></label></div>
											<div class="col-md-9">
												<div class="input-group">
													<input type="text" class="form-control" id="tg_test_chatid">
													<span class="input-group-btn"><button type="button" class="btn btn-default" id="exunity-tg-test"><?php echo _('Send test') ?></button></span>
												</div>
												<span class="help-block" id="exunity-tg-test-result"></span>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
					<div id="exset-phones" class="tab-pane" role="tabpanel">
						<div class="element-container">
							<div class="row">
								<div class="col-md-12">
									<div class="row">
										<div class="form-group">
											<div class="col-md-3"><label class="control-label" for="provision_base_url"><?php echo _('Provisioning base URL') ?></label></div>
											<div class="col-md-9">
												<input type="text" class="form-control" id="provision_base_url" name="provision_base_url" value="<?php echo htmlentities($settings['provision_base_url']) ?>">
												<span class="help-block"><?php echo sprintf(_('Phones fetch configs from %s/{mac}.cfg (Grandstream: /cfg{mac}.xml)'), htmlentities($provision_url)) ?></span>
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
											<div class="col-md-3"><label class="control-label" for="provision_sip_host"><?php echo _('SIP host for phones') ?></label></div>
											<div class="col-md-9"><input type="text" class="form-control" id="provision_sip_host" name="provision_sip_host" value="<?php echo htmlentities($settings['provision_sip_host']) ?>" placeholder="<?php echo _('Empty = PBX external IP') ?>"></div>
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
											<div class="col-md-3"><label class="control-label" for="provision_sip_port"><?php echo _('SIP port') ?></label></div>
											<div class="col-md-9"><input type="text" class="form-control" id="provision_sip_port" name="provision_sip_port" value="<?php echo htmlentities($settings['provision_sip_port']) ?>"></div>
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
											<div class="col-md-3"><label class="control-label" for="provision_sip_transport"><?php echo _('SIP transport') ?></label></div>
											<div class="col-md-9">
												<select class="form-control" id="provision_sip_transport" name="provision_sip_transport">
													<?php foreach (['udp', 'tcp', 'tls'] as $t) { ?>
														<option value="<?php echo $t ?>" <?php echo $settings['provision_sip_transport'] === $t ? 'selected' : '' ?>><?php echo strtoupper($t) ?></option>
													<?php } ?>
												</select>
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
											<div class="col-md-3"><label class="control-label" for="default_timezone"><?php echo _('Timezone') ?></label></div>
											<div class="col-md-9"><input type="text" class="form-control" id="default_timezone" name="default_timezone" value="<?php echo htmlentities($settings['default_timezone']) ?>"></div>
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
											<div class="col-md-3"><label class="control-label" for="default_language"><?php echo _('Language') ?></label></div>
											<div class="col-md-9"><input type="text" class="form-control" id="default_language" name="default_language" value="<?php echo htmlentities($settings['default_language']) ?>"></div>
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
											<div class="col-md-3"><label class="control-label" for="default_admin_password"><?php echo _('Phone web admin password') ?></label></div>
											<div class="col-md-9"><input type="text" class="form-control confidential" id="default_admin_password" name="default_admin_password" value="<?php echo htmlentities($settings['default_admin_password']) ?>"></div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
					<div id="exset-phonebook" class="tab-pane" role="tabpanel">
						<div class="element-container">
							<div class="row">
								<div class="col-md-12">
									<div class="row">
										<div class="form-group">
											<div class="col-md-3"><label class="control-label" for="phonebook_enabled"><?php echo _('Remote phonebook') ?></label></div>
											<div class="col-md-9">
												<select class="form-control" id="phonebook_enabled" name="phonebook_enabled">
													<option value="yes" <?php echo ($settings['phonebook_enabled'] ?? 'yes') === 'yes' ? 'selected' : '' ?>><?php echo _('Enabled') ?></option>
													<option value="no" <?php echo ($settings['phonebook_enabled'] ?? 'yes') === 'no' ? 'selected' : '' ?>><?php echo _('Disabled') ?></option>
												</select>
												<span class="help-block"><?php echo _('Phones download a company directory over HTTP and show names in the phone book and while dialing. After saving, phones pick up the URL on the next provision.') ?></span>
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
											<div class="col-md-3"><label class="control-label" for="phonebook_name"><?php echo _('Phonebook name on the phone') ?></label></div>
											<div class="col-md-9">
												<input type="text" class="form-control" id="phonebook_name" name="phonebook_name" value="<?php echo htmlentities($settings['phonebook_name'] ?? 'Company') ?>">
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
											<div class="col-md-3"><label class="control-label"><?php echo _('Contact Manager groups') ?></label></div>
											<div class="col-md-9">
												<?php if (empty($phonebook_cm_ok)) { ?>
													<p class="help-block"><?php echo _('Install the Contact Manager module to publish named groups. PBX extensions can still be included below.') ?></p>
												<?php } elseif (empty($cm_groups)) { ?>
													<p class="help-block"><?php echo _('No public Contact Manager groups yet. Create a group in Contact Manager, then return here.') ?></p>
												<?php } else { ?>
													<div class="exunity-sticky-queues">
														<?php foreach ($cm_groups as $g) { ?>
															<div class="checkbox">
																<label>
																	<input type="checkbox" name="phonebook_groups[]" value="<?php echo htmlentities($g['id']) ?>" <?php echo in_array($g['id'], $phonebook_groups ?? [], true) ? 'checked' : '' ?>>
																	<strong><?php echo htmlentities($g['name']) ?></strong>
																	<?php if ($g['type'] !== '') { ?>
																		— <?php echo htmlentities($g['type']) ?>
																	<?php } ?>
																</label>
															</div>
														<?php } ?>
													</div>
												<?php } ?>
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
											<div class="col-md-3"><label class="control-label" for="phonebook_include_extensions"><?php echo _('Include PBX extensions') ?></label></div>
											<div class="col-md-9">
												<select class="form-control" id="phonebook_include_extensions" name="phonebook_include_extensions">
													<option value="yes" <?php echo ($settings['phonebook_include_extensions'] ?? 'yes') === 'yes' ? 'selected' : '' ?>><?php echo _('Yes') ?></option>
													<option value="no" <?php echo ($settings['phonebook_include_extensions'] ?? 'yes') === 'no' ? 'selected' : '' ?>><?php echo _('No') ?></option>
												</select>
												<span class="help-block"><?php echo _('Add every extension from Applications → Extensions. Use this when the Contact Manager internal group is empty.') ?></span>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
						<?php if (!empty($phonebook_urls)) { ?>
						<div class="element-container">
							<div class="row">
								<div class="col-md-12">
									<div class="row">
										<div class="form-group">
											<div class="col-md-3"><label class="control-label"><?php echo _('Phonebook URLs') ?></label></div>
											<div class="col-md-9">
												<p class="help-block"><?php echo _('These URLs are written into phone templates. MicroSIP uses JSON (Directory of users / usersDirectory) and also accepts the same URL pasted in Settings.') ?></p>
												<?php foreach ($phonebook_urls as $vendor => $url) { ?>
													<div style="margin-bottom:8px">
														<strong><?php echo htmlentities(ucfirst($vendor)) ?></strong>
														<input type="text" class="form-control" readonly value="<?php echo htmlentities($url) ?>" onclick="this.select()">
													</div>
												<?php } ?>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
						<?php } ?>
					</div>
					<div id="exset-recordings" class="tab-pane" role="tabpanel">
						<div class="element-container">
							<div class="row">
								<div class="col-md-12">
									<div class="row">
										<div class="form-group">
											<div class="col-md-3"><label class="control-label" for="cdr_recording_keep_days"><?php echo _('Keep CDR recordings (days)') ?></label></div>
											<div class="col-md-9">
												<input type="number" class="form-control" id="cdr_recording_keep_days" name="cdr_recording_keep_days" min="0" max="3650" value="<?php echo htmlentities($settings['cdr_recording_keep_days']) ?>">
												<span class="help-block"><?php echo _('Delete only the audio files of old CDR recordings. Call history itself is never removed. 0 = keep audio forever. Cleanup runs every night at 03:15.') ?></span>
												<div class="input-group" style="max-width: 420px; margin-top: 8px;">
													<span class="input-group-btn"><button type="button" class="btn btn-default" id="exunity-cdr-purge"><?php echo _('Delete old recordings now') ?></button></span>
												</div>
												<span class="help-block" id="exunity-cdr-purge-result">
													<?php if (!empty($retention_last['at'])) { ?>
														<?php echo sprintf(_('Last cleanup: %s, deleted %d files, %d already missing, %d CDR links cleared.'), htmlentities($retention_last['at']), (int) ($retention_last['deleted'] ?? 0), (int) ($retention_last['missing'] ?? 0), (int) ($retention_last['cleared'] ?? 0)) ?>
													<?php } ?>
												</span>
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
											<div class="col-md-3"><label class="control-label" for="stereo_record"><?php echo _('Stereo recordings') ?></label></div>
											<div class="col-md-9">
												<select class="form-control" id="stereo_record" name="stereo_record">
													<option value="no" <?php echo ($settings['stereo_record'] ?? 'no') === 'no' ? 'selected' : '' ?>><?php echo _('Disabled (mono mix)') ?></option>
													<option value="yes" <?php echo ($settings['stereo_record'] ?? 'no') === 'yes' ? 'selected' : '' ?>><?php echo _('Enabled') ?></option>
												</select>
												<span class="help-block"><?php echo _('Save new call recordings as stereo WAV: left = party A (the channel being recorded, usually the caller), right = party B (the other party). Headphones: A in the left ear, B in the right. Existing files stay as they are. Apply Config after saving. Requires sox.') ?></span>
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
											<div class="col-md-3"><label class="control-label" for="record_mp3"><?php echo _('Compress recordings to MP3') ?></label></div>
											<div class="col-md-9">
												<select class="form-control" id="record_mp3" name="record_mp3">
													<option value="no" <?php echo ($settings['record_mp3'] ?? 'no') === 'no' ? 'selected' : '' ?>><?php echo _('Disabled (keep original format)') ?></option>
													<option value="yes" <?php echo ($settings['record_mp3'] ?? 'no') === 'yes' ? 'selected' : '' ?>><?php echo _('Enabled') ?></option>
												</select>
												<span class="help-block"><?php echo _('After the call, convert the recording to MP3 and delete the WAV. Works together with stereo (stereo MP3: A left, B right). Existing files stay as they are. Apply Config after saving. Requires ffmpeg or lame.') ?></span>
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
											<div class="col-md-3"><label class="control-label" for="record_mp3_bitrate"><?php echo _('MP3 bitrate (kbps)') ?></label></div>
											<div class="col-md-9">
												<select class="form-control" id="record_mp3_bitrate" name="record_mp3_bitrate">
													<?php foreach ([32, 48, 64, 96, 128] as $br) { ?>
														<option value="<?php echo $br ?>" <?php echo (int) ($settings['record_mp3_bitrate'] ?? 64) === $br ? 'selected' : '' ?>><?php echo $br ?></option>
													<?php } ?>
												</select>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
					<div id="exset-queues" class="tab-pane" role="tabpanel">
						<div class="element-container">
							<div class="row">
								<div class="col-md-12">
									<div class="row">
										<div class="form-group">
											<div class="col-md-3"><label class="control-label"><?php echo _('Sticky last agent') ?></label></div>
											<div class="col-md-9">
												<?php if (empty($sticky_queues)) { ?>
													<p class="help-block"><?php echo _('No queues yet. Create a queue, then enable sticky last agent here or on the queue page (eXTools tab).') ?></p>
												<?php } else { ?>
													<div class="exunity-sticky-queues">
														<?php foreach ($sticky_queues as $q) { ?>
															<div class="checkbox">
																<label>
																	<input type="checkbox" name="sticky_queues[]" value="<?php echo htmlentities($q['extension']) ?>" <?php echo !empty($q['sticky']) ? 'checked' : '' ?>>
																	<strong><?php echo htmlentities($q['extension']) ?></strong>
																	<?php if ($q['name'] !== '') { ?>
																		— <?php echo htmlentities($q['name']) ?>
																	<?php } ?>
																</label>
															</div>
														<?php } ?>
													</div>
												<?php } ?>
												<span class="help-block"><?php echo _('Enable per queue. If this caller already talked to an agent of that queue (inbound answer or outbound from the agent), the next inbound call rings the same agent first. Busy, reject, timeout, or offline → the call continues to the queue as usual. You can also toggle this on the queue itself (eXTools tab). Apply Config after saving.') ?></span>
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
											<div class="col-md-3"><label class="control-label" for="sticky_timeout"><?php echo _('Last agent ring time (seconds)') ?></label></div>
											<div class="col-md-9">
												<input type="number" class="form-control" id="sticky_timeout" name="sticky_timeout" min="5" max="60" value="<?php echo htmlentities($settings['sticky_timeout'] ?? '15') ?>">
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
											<div class="col-md-3"><label class="control-label" for="sticky_days"><?php echo _('Remember last agent (days)') ?></label></div>
											<div class="col-md-9">
												<input type="number" class="form-control" id="sticky_days" name="sticky_days" min="1" max="3650" value="<?php echo htmlentities($settings['sticky_days'] ?? '90') ?>">
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</form>
	</div>
</div>
<script>
$(function () {
	var form = document.forms.exunitysettings;
	if (!form) {
		return;
	}
	var key = 'exunity-settings-tab';
	var saved = null;
	try {
		saved = localStorage.getItem(key);
	} catch (e) {}
	if (saved && $('#exunity-settings-tabs a[href="#' + saved + '"]').length) {
		$('#exunity-settings-tabs a[href="#' + saved + '"]').tab('show');
	}
	$('#exunity-settings-tabs a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
		var id = ($(e.target).attr('href') || '').replace('#', '');
		if (!id) {
			return;
		}
		try {
			localStorage.setItem(key, id);
		} catch (err) {}
	});
	form.addEventListener('invalid', function (e) {
		var pane = e.target.closest('.tab-pane');
		if (pane && !pane.classList.contains('active')) {
			$('#exunity-settings-tabs a[href="#' + pane.id + '"]').tab('show');
		}
	}, true);
});
</script>
