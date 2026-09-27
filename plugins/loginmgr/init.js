plugin.loadLang();

if(plugin.canChangeOptions())
{
	plugin.accaddAndShowSettings = theWebUI.addAndShowSettings;
	theWebUI.addAndShowSettings = function(arg)
	{
		if(plugin.enabled)
		{
			$.each( theWebUI.theAccounts, function(name,val)
			{
				$('#'+name+'_lmenabled').prop("checked", (val.enabled==1));
				$('#'+name+'_lmlogin').val(val.login);
				$('#'+name+'_lmauto').val(val.auto);
				$('#'+name+'_lmpassword').val('');
				$('#'+name+'_lmclear_password').prop('checked', false);
				$('#'+name+'_lmenabled').trigger('change');
			});
		}
		plugin.accaddAndShowSettings.call(theWebUI,arg);
	}

	plugin.accWasChanged = function()
	{
		var ret = false;
		$.each( theWebUI.theAccounts, function(name,val)
		{
			if( ($('#'+name+'_lmenabled').prop("checked") ^ val.enabled) ||
				($('#'+name+'_lmauto').val()!=val.auto) ||
				($('#'+name+'_lmlogin').val()!=val.login) ||
				($('#'+name+'_lmpassword').val()!='') ||
				$('#'+name+'_lmclear_password').prop('checked'))
			{
				ret = true;
				return(false);
			}
		});
		return(ret);
	}

	plugin.accSaved = function(_response, submitted)
	{
		$.each(submitted, function(name, value)
		{
			const password = $('#'+name+'_lmpassword');
			if(password.val() === value.password)
				password.val('');
			const clear = $('#'+name+'_lmclear_password');
			if(clear.prop('checked') === value.clear)
				clear.prop('checked', false).trigger('change');
		});
	}

	plugin.accSettings = theWebUI.setSettings;
	theWebUI.setSettings = function()
	{
		plugin.accSettings.call(this);
		if(plugin.enabled && plugin.accWasChanged())
		{
			const submitted = {};
			$.each(theWebUI.theAccounts, function(name)
			{
				submitted[name] = {
					password: $('#'+name+'_lmpassword').val(),
					clear: $('#'+name+'_lmclear_password').prop('checked')
				};
			});
			this.request("?action=setacc", [plugin.accSaved, plugin, submitted]);
		}
	}

	rTorrentStub.prototype.setacc = function()
	{
		var s = '';
		$.each( theWebUI.theAccounts, function(name,val)
		{
			s+=("&"+name+"_enabled="+($('#'+name+'_lmenabled').prop("checked") ? 1 : 0)+
				"&"+name+"_auto="+$('#'+name+'_lmauto').val()+
				"&"+name+"_login="+encodeURIComponent($('#'+name+'_lmlogin').val()).trim()+
				"&"+name+"_password="+encodeURIComponent($('#'+name+'_lmpassword').val()).trim());
			if($('#'+name+'_lmclear_password').prop('checked'))
				s += "&"+name+"_clear_password=1";
		});
		this.content = "mode=set"+s;
	        this.contentType = "application/x-www-form-urlencoded";
		this.mountPoint = "plugins/loginmgr/action.php";
		this.dataType = "script";
	}
}

plugin.onLangLoaded = function() {
	const s = Object.entries(theWebUI.theAccounts).map(([name, acct]) => {
		const fieldset = $("<fieldset>").append(
		$("<legend>").text(name),
		$("<div>").addClass("row").append(
			$("<div>").addClass("col-md-6").append(
				$("<input>").attr({type:"checkbox", id:`${name}_lmenabled`, onchange:`linked(this, 0, ['${name}_lmlogin','${name}_lmpassword','${name}_lmauto'])`}),
				$("<label>").attr({for:`${name}_lmenabled`, id:`lbl_${name}_lmenabled`}).text(theUILang.Enabled),
			),
			$("<div>").addClass("col-4 col-md-2").append(
				$("<label>").attr({id:`lbl_${name}_lmauto`, for:`${name}_lmauto`}).addClass("disabled").text(theUILang.accAuto + ": "),
			),
			$("<div>").addClass("col-8 col-md-4").append(
				$("<select>").attr({id:`${name}_lmauto`, maxlength:64}).append(
					...[
						[0, "acAutoNone"], [86400, "acAutoDay"], [604800, "acAutoWeek"], [2592000, "acAutoMonth"],
					].map(([value, text]) => $("<option>").val(value).text(theUILang[text])),
				),
			),
		),
		$("<div>").addClass("row").append(
			$("<div>").addClass("col-4 col-md-2").append(
				$("<label>").attr({id:`lbl_${name}_lmlogin`, for:`${name}_lmlogin`}).addClass("disabled").text(theUILang.accLogin + ": "),
			),
			$("<div>").addClass("col-8 col-md-4").append(
				$("<input>").attr({type:"text", id:`${name}_lmlogin`, maxlength:32}),
			),
			$("<div>").addClass("col-4 col-md-2").append(
				$("<label>").attr({id:`lbl_${name}_lmpassword`, for:`${name}_lmpassword`}).addClass("disabled").text(theUILang.accPassword + ": "),
			),
			$("<div>").addClass("col-8 col-md-4").append(
				$("<input>").attr({type:"password", id:`${name}_lmpassword`, maxlength:64}),
			),
		),
			$("<div>").addClass("row").append(
				$("<div>").addClass("col-12").append(
					$("<input>").attr({type:"checkbox", id:`${name}_lmclear_password`}),
					$("<label>").attr({for:`${name}_lmclear_password`}).text(theUILang.accClearPassword || "Clear saved password"),
				),
			),
		);
		const enabled = fieldset.find(`#${name}_lmenabled`);
		const clear = fieldset.find(`#${name}_lmclear_password`);
		const password = fieldset.find(`#${name}_lmpassword`);
		const syncPassword = () => {
			password.prop("disabled", clear.prop("checked") || !enabled.prop("checked"));
		};
		clear.on("change", function() {
			if(this.checked)
				password.val("");
			syncPassword();
		});
		enabled.on("change", syncPassword);
		if(acct.configurationRequired)
		{
			const warning = $("<div>").addClass("alert alert-warning").attr("role", "alert")
				.text(theUILang.accOriginRequired || "Set $yggTorrentOrigin in conf/config.php, plugins/loginmgr/conf.local.php, or conf/users/<user>/plugins/loginmgr/conf.php to a trusted HTTPS origin. Session downloads and automatic login are disabled.");
			warning.toggle(acct.enabled == 1);
			fieldset.append(warning);
			fieldset.find(`#${name}_lmenabled`).on("change", function() { warning.toggle(this.checked); });
		}
		return fieldset;
	});
	this.attachPageToOptions(
		$("<div>").attr("id","st_loginmgr").append(...s)[0],
		theUILang.accAccounts,
	);
}

plugin.onRemove = function() {
	this.removePageFromOptions("st_loginmgr");
}
