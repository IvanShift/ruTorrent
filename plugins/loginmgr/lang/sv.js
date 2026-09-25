/*
 * PLUGIN LoginMGR
 *
 * Swedish language file.
 *
 * Author: Magnus Holm (holmen@brasse.se)
 */

 theUILang.accLogin		= "Inloggning";
 theUILang.accPassword		= "Lösenord";
 theUILang.accAccounts		= "Konton";
 theUILang.accAuto		= "Automatisk inloggning";
 theUILang.acAutoNone		= "Ingen";
 theUILang.acAutoDay		= "Varje dag";
 theUILang.acAutoWeek		= "Varje vecka";
 theUILang.acAutoMonth		= "Varje månad";

theUILang.accOriginRequired = "Set $yggTorrentOrigin in conf/config.php (or a loginmgr override) to a trusted HTTPS origin. Session downloads and automatic login are disabled.";

thePlugins.get("loginmgr").langLoaded();
