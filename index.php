<?php

/**
 * This is the entry point for every request that should return HTML
 * (one exception is that it also returns translated text for javascript).
 */

// Bootstrap the script
require_once 'server/includes/bootstrap.php';

/*
 * Get the favicon either from theme or use the default.
 *
 * @param string theme the users theme
 * @return string favicon
 */
function getFavicon($theme) {
	$favicon = Theming::getFavicon($theme);

	if (!isset($favicon) || $favicon === false) {
		$favicon = 'client/resources/images/favicon.ico?kv2.2.0';
	}

	return $favicon;
}

// If the user wants to logout (and is not using single-signon)
// then destroy the session and redirect to this page, so the login page
// will be shown

if (isset($_GET['logout'])) {
	if (isset($_SESSION['_keycloak_auth'])) {
		$keycloak_auth = $_SESSION['_keycloak_auth']->logout();
		header("Location:" . $keycloak_auth . "");
	}
	else {
		// GET variable user will be set when the user was logged out because of session timeout
		// or because he logged out in another window.
		$username = sanitizeGetValue('user', '', USERNAME_REGEX);
		$location = rtrim(dirname((string) $_SERVER['PHP_SELF']), '/') . '/';
		header('Location: ' . $location . ($username ? '?user=' . rawurlencode((string) $username) : ''), true, 303);
	}
	$webappSession->destroy();

	exit;
}

// Check if an action GET-parameter was sent with the request.
// This parameter is set when the webapp was opened by clicking on
// a mailto: link in the browser.
// If so, we will store it in the session, so we can use it later.
if (isset($_GET['action']) && !empty($_GET['action'])) {
	storeURLDataToSession();
}

// Check if the continue parameter was set. This will be set e.g. when someone
// uses the grommunio Web to login to another application with OpenID Connect.
if (isset($_GET['continue']) && !empty($_GET['continue']) && !isset($_GET['wacontinue'])) {
	$_SESSION['continue'] = $_GET['continue'];
}

// Try to authenticate the user
WebAppAuthentication::authenticate();

$webappTitle = defined('WEBAPP_TITLE') && WEBAPP_TITLE ? WEBAPP_TITLE : 'grommunio Web';
if (isset($_COOKIE['webapp_title'])) {
	$webappTitle .= " – " . $_COOKIE['webapp_title'];
}

// If we could not authenticate the user, we will show the login page
if (!WebAppAuthentication::isAuthenticated()) {
	// Get language from the cookie, or from the language that is set by the admin
	$Language = new Language();
	$lang = $_COOKIE['lang'] ?? LANG;
	$lang = $Language->resolveLanguage($lang);
	$Language->setLanguage($lang);

	// If GET parameter 'load' is defined, we defer handling to the load.php script
	if (isset($_GET['load']) && $_GET['load'] !== 'logon') {
		include BASE_PATH . 'server/includes/load.php';

		exit;
	}

	// Set some template variables for the login page
	$version = 'grommunio Web ' . trim(file_get_contents('version'));
	$user = sanitizeGetValue('user', '', USERNAME_REGEX);

	$url = '?logon';

	if (isset($_GET["logout"]) && $_GET["logout"] == "auto") {
		$error = _("You have been automatically logged out");
	}
	else {
		$error = WebAppAuthentication::getErrorMessage();
		if (empty($error) && useSecureCookies() && getRequestProtocol() == 'http') {
			header("HTTP/1.0 400 Bad Request");
			include BASE_PATH . 'server/includes/templates/BadRequest.php';
			error_log("Rejected insecure request as configuration for 'SECURE_COOKIES' is true.");

			exit;
		}
	}

	// If a username was passed as GET parameter we will prefill the username input
	// of the login form with it.
	$user = isset($_GET['user']) ? htmlentities((string) $_GET['user']) : '';

	// Lets add a header when login failed (DeskApp needs it to identify failed login attempts)
	if (WebAppAuthentication::getErrorCode() !== NOERROR) {
		header("X-grommunio-Hresult: " . get_mapi_error_name(WebAppAuthentication::getErrorCode()));
	}

	// Set a template variable for the favicon of the login, welcome, and webclient page
	$theme = Theming::getActiveTheme();
	$favicon = getFavicon(Theming::getActiveTheme());
	include BASE_PATH . 'server/includes/templates/login.php';

	exit;
}

// The user is authenticated! Let's get ready to start the webapp.
// If the user just logged in or if url data was stored in the session,
// we will redirect to make sure that a browser refresh will not post
// the credentials again, and that the url data is taken away from the
// url in the address bar (so a browser refresh will not pass them again)
if (isset($_GET['code']) || (WebAppAuthentication::isUsingLoginForm() || isset($_GET['action']) && !empty($_GET['action']))) {
	$location = rtrim(dirname((string) $_SERVER['PHP_SELF']), '/') . '/';
	header('Location: ' . $location, true, 303);

	exit;
}

// TODO: we could replace all references to $GLOBALS['mapisession']
// with WebAppAuthentication::getMAPISession(), that way we would
// lose at least one GLOBAL (because globals suck)
$GLOBALS['mapisession'] = WebAppAuthentication::getMAPISession();

// check if it's DB or LDAP for the password plugin
$result = @json_decode(@file_get_contents(ADMIN_API_STATUS_ENDPOINT, false), true);
if (isset($result['ldap']) && $result['ldap']) {
	$GLOBALS['usersinldap'] = true;
}

// Instantiate Plugin Manager and init the plugins (btw: globals suck)
$GLOBALS['PluginManager'] = new PluginManager(ENABLE_PLUGINS);
$GLOBALS['PluginManager']->detectPlugins(DISABLED_PLUGINS_LIST);

// Initialize plugins and prevent any output which might be written as
// plugins might be uncleanly output white-space and other stuff. We must
// not allow this here as it can destroy the response data.
ob_start();
$GLOBALS['PluginManager']->initPlugins(DEBUG_LOADER);
ob_end_clean();

// Create globals settings object (btw: globals suck)
$GLOBALS["settings"] = new Settings();

// Create global operations object
$GLOBALS["operations"] = new Operations();

// If webapp feature is not enabled for the user,
// we will show the login page with appropriated error message.
if ($GLOBALS['mapisession']->isWebappDisableAsFeature()) {
	header("X-grommunio-Hresult: " . get_mapi_error_name(ecLoginPerm));

	$error = _("Sorry, access to grommunio Web is not available with this user account. Please contact your system administrator.");
	// Set some template variables for the login page
	$user = sanitizeGetValue('user', '', USERNAME_REGEX);

	$url = '?logon';
	// Set a template variable for the favicon of the login, welcome, and webclient page
	$theme = Theming::getActiveTheme();
	$favicon = getFavicon(Theming::getActiveTheme());
	$webappSession->destroy();
	// Include the login template
	include BASE_PATH . 'server/includes/templates/login.php';

	exit;
}

$Language = new Language();

// Set session settings (language & style)
foreach ($GLOBALS["settings"]->getSessionSettings($Language) as $key => $value) {
	$_SESSION[$key] = $value;
}

// Get language from the request, or the session, or the user settings, or the config
if (isset($_REQUEST["language"]) && $Language->is_language($_REQUEST["language"])) {
	$lang = $_REQUEST["language"];
	$GLOBALS["settings"]->set("zarafa/v1/main/language", $lang);
}
elseif (isset($_SESSION["lang"])) {
	$lang = $_SESSION["lang"];
	$GLOBALS["settings"]->set("zarafa/v1/main/language", $lang);
}
else {
	$lang = $GLOBALS["settings"]->get("zarafa/v1/main/language");
	if (empty($lang)) {
		$lang = LANG;
		$GLOBALS["settings"]->set("zarafa/v1/main/language", $lang);
	}
}

$Language->setLanguage($lang);
setcookie('lang', (string) $lang, [
	'expires' => time() + 31536000,
	'path' => '/',
	'domain' => '',
	'secure' => true,
	'httponly' => true,
	'samesite' => 'Strict',
]);

// add extra header
header("X-grommunio: " . trim(file_get_contents('version')));

// Set a template variable for the favicon of the login, welcome, and webclient page
$theme = Theming::getActiveTheme();
$favicon = getFavicon(Theming::getActiveTheme());
$hideFavorites = $GLOBALS["settings"]->get("zarafa/v1/contexts/hierarchy/hide_favorites") ? 'hideFavorites' : '';
$scrollFavorites = $GLOBALS["settings"]->get("zarafa/v1/contexts/hierarchy/scroll_favorites") ? 'scrollFavorites' : '';
$unreadBorders = $GLOBALS["settings"]->get("zarafa/v1/main/unread_borders") === false ? '' : 'k-unreadborders';

// If GET parameter 'load' is defined, we defer handling to the load.php script
if (isset($_GET['load'])) {
	include BASE_PATH . 'server/includes/load.php';

	exit;
}

if (ENABLE_WELCOME_SCREEN && $GLOBALS["settings"]->get("zarafa/v1/main/show_welcome") !== false) {
	// These hooks are defined twice (also when there is a "load" argument supplied)
	$GLOBALS['PluginManager']->triggerHook("server.index.load.welcome.before");
	include BASE_PATH . 'server/includes/templates/welcome.php';
	$GLOBALS['PluginManager']->triggerHook("server.index.load.welcome.after");
}
else {
	// Set the show_welcome to false, so that when the admin is changing the
	// ENABLE_WELCOME_SCREEN option to false after some time, the users who are already
	// using grommunio Web are not bothered with the Welcome Screen.
	$GLOBALS["settings"]->set("zarafa/v1/main/show_welcome", false);

	// Clean up old state files in tmp/session/
	$state = new State("index");
	$state->clean();

	// Clean up old attachments in tmp/attachments/
	$state = new AttachmentState();
	$state->clean();

	// Fetch the hierarchy state cache for unread counters notifications for subfolders
	$counterState = new State('counters_sessiondata');
	$counterState->open();
	$counterState->write("sessionData", updateHierarchyCounters());
	$counterState->close();

	// clean search folders
	cleanSearchFolders();

	// These hooks are defined twice (also when there is a "load" argument supplied)
	$GLOBALS['PluginManager']->triggerHook("server.index.load.main.before");

	// Include webclient
	include BASE_PATH . 'server/includes/templates/webclient.php';
	$GLOBALS['PluginManager']->triggerHook("server.index.load.main.after");
}
