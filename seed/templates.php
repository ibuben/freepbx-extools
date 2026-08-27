<?php
return [
	[
		'code' => 'fanvil',
		'name' => 'Fanvil',
		'models' => ['X3SLite', 'X4G', 'X5S', 'X5U', 'X1S'],
		'content_type' => 'text/plain; charset=utf-8',
		'template_name' => 'Fanvil',
		'body' => <<<'CFG'
<<VOIP CONFIG FILE>>Version:2.0000000000

<SIP CONFIG MODULE>
SIP  Port          :{{sip_port}}
--SIP Line List--  :
SIP1 Phone Number       :{{sip_extension}}
SIP1 Display Name       :{{display_name}}
SIP1 Sip Name           :{{sip_server}}
SIP1 Register Addr      :{{sip_server}}
SIP1 Register Port      :{{sip_port}}
SIP1 Register User      :{{sip_extension}}
SIP1 Register Pswd      :{{sip_password}}
SIP1 Register TTL       :3600
SIP1 Enable Reg         :1
SIP1 Proxy Addr         :{{sip_server}}
SIP1 Proxy Port         :{{sip_port}}
SIP1 Transport          :0
</SIP CONFIG MODULE>
<MMI CONFIG MODULE>
Web Language            :{{language_fanvil}}
</MMI CONFIG MODULE>
<TIME CONFIG MODULE>
Time Zone               :{{timezone}}
</TIME CONFIG MODULE>
<PHONEBOOK CONFIG MODULE>
--Phone Book List--  :
Phone 1 :Name:{{phonebook_name}}
Phone 1 :Addr:{{phonebook_fanvil_url}}
</PHONEBOOK CONFIG MODULE>
CFG,
	],
	[
		'code' => 'grandstream',
		'name' => 'Grandstream',
		'models' => ['GXP2130', 'GXP2140', 'GXP2160', 'GXP1625', 'GXP1620'],
		'content_type' => 'text/plain; charset=utf-8',
		'template_name' => 'Grandstream GXP',
		'body' => <<<'CFG'
P2={{admin_password}}
P271=1
P270={{sip_extension}}
P35={{sip_extension}}
P36={{sip_extension}}
P34={{sip_password}}
P47={{sip_server}}
P40={{sip_port}}
P3={{display_name}}
P1362={{language_grandstream}}
P64={{timezone_grandstream}}
P330=1
P331={{phonebook_grandstream_url}}
P332=60
CFG,
	],
	[
		'code' => 'yealink',
		'name' => 'Yealink',
		'models' => ['SIP-T46S', 'SIP-T42S', 'SIP-T31P', 'SIP-T21P'],
		'content_type' => 'text/plain; charset=utf-8',
		'template_name' => 'Yealink T4x / T2x',
		'body' => <<<'CFG'
account.1.enable = 1
account.1.label = {{display_name}}
account.1.display_name = {{display_name}}
account.1.user_name = {{sip_extension}}
account.1.auth_name = {{sip_extension}}
account.1.password = {{sip_password}}
account.1.sip_server.1.address = {{sip_server}}
account.1.sip_server.1.port = {{sip_port}}
static.lang.wui = {{language_yealink}}
static.lang.gui = {{language_yealink}}
local_time.time_zone = {{timezone_yealink}}
remote_phonebook.data.1.url = {{phonebook_yealink_url}}
remote_phonebook.data.1.name = {{phonebook_name}}
search_in_dialing.remote_phonebook.enable = 1
CFG,
	],
	[
		'code' => 'microsip',
		'name' => 'MicroSIP',
		'models' => ['MicroSIP'],
		'content_type' => 'application/json; charset=utf-8',
		'template_name' => 'MicroSIP',
		'body' => <<<'JSON'
{
  "account": {
    "label": "{{account_label}}",
    "server": "{{microsip_server}}",
    "proxy": "",
    "domain": "{{sip_server}}",
    "username": "{{sip_extension}}",
    "password": "{{sip_password}}",
    "authID": "",
    "displayName": "{{display_name}}",
    "transport": "{{sip_transport}}",
    "srtp": "",
    "registerRefresh": 300,
    "keepAlive": 15,
    "publish": false,
    "ice": false,
    "allowRewrite": true,
    "disableSessionTimer": false,
    "voicemailNumber": "",
    "dialingPrefix": "",
    "dialPlan": "",
    "hideCID": false,
    "publicAddr": ""
  },
  "usersDirectory": "{{phonebook_microsip_url}}"
}
JSON,
	],
];
