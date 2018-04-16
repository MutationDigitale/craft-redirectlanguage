<?php

namespace mutation\redirectlanguage;

use craft\base\Plugin;
use craft\elements\Entry;

class RedirectLanguage extends Plugin
{
	CONST COOKIE_NAME = 'craft_lang';

	public function init()
	{
		parent::init();

		if (!(isset($_SERVER['REQUEST_METHOD']) &&
			$_SERVER['REQUEST_METHOD'] === 'GET' &&
			\Craft::$app->request->isSiteRequest &&
			!\Craft::$app->request->isLivePreview &&
			!\Craft::$app->request->isAjax &&
			\Craft::$app->request->isGet)
		) {
			return;
		}

		$available_languages = array();
		$full_languages = array();
		foreach (\Craft::$app->i18n->getSiteLocales() as $locale) {
			$full_languages[] = $locale;
			$available_languages[] = substr($locale, 0, 2);
		}

		$default_lang = substr(\Craft::$app->i18n->getPrimarySiteLocale(), 0, 2);

		$path = trim($_SERVER['REQUEST_URI'], '/');

		$url_language = substr($path, 0, 2);

		if (\in_array($url_language, $available_languages, true)) {
			setcookie(self::COOKIE_NAME, $url_language, 0, '/');
			return;
		}

		if (preg_match('/^\/(' . implode('|', $available_languages) . ')(\/.*|)$/',
			\Craft::$app->request->absoluteUrl)) {
			$current_lang = substr(\Craft::$app->i18n->getLocaleById(\Craft::$app->language), 0, 2);
			setcookie(self::COOKIE_NAME, $current_lang, 0, '/');
			return;
		}

		$browser_lang = $this->prefered_language(
			$available_languages,
			isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE']) : null,
			$default_lang
		);
		$cookie_lang = $_COOKIE[self::COOKIE_NAME] ?? '';

		if (!\in_array($cookie_lang, $available_languages, true)) {
			$cookie_lang = '';
		}

		$lang_to_redirect = $cookie_lang !== '' ? $cookie_lang : $browser_lang;

		if ($path === '') {
			\Craft::$app->response->redirect($lang_to_redirect . '/');
			return;
		}

		$segments = explode('/', $path);

		$full_lang_to_redirect = null;
		foreach ($full_languages as $full_language) {
			if (strpos($full_language, $lang_to_redirect) === 0) {
				$full_lang_to_redirect = $full_language;
			}
		}
		$doRedirect = $this->check_entries_by_segments($segments, $full_lang_to_redirect);

		if ($doRedirect === false) {
			foreach ($full_languages as $full_language) {
				if (strpos($full_language, $lang_to_redirect) !== 0) {
					$doRedirect = $this->check_entries_by_segments($segments, $full_language);

					if ($doRedirect) {
						$lang_to_redirect = substr($full_language, 0, 2);
						break;
					}
				}
			}
		}

		if ($doRedirect) {
			\Craft::$app->response->redirect('/' . $lang_to_redirect . '/' . $path);
		}
	}

	private function prefered_language($available_languages, $http_accept_language, $default_language)
	{
		$available_languages = array_flip($available_languages);
		$langs = array();

		preg_match_all('~([\w-]+)(?:[^,\d]+([\d.]+))?~', strtolower($http_accept_language), $matches, PREG_SET_ORDER);
		foreach ($matches as $match) {
			list($a) = explode('-', $match[1]) + array('', '');
			$value = isset($match[2]) ? (float)$match[2] : 1.0;
			if (isset($available_languages[$match[1]])) {
				$langs[$match[1]] = $value;
				continue;
			}
			if (isset($available_languages[$a])) {
				$langs[$a] = $value - 0.1;
			}
		}

		if ($langs) {
			arsort($langs);
			return key($langs);
		}

		return $default_language;
	}

	private function check_entries_by_segments($segments, $language)
	{
		$allEntries = true;

		$level = 1;
		foreach ($segments as $segment) {
			$criteria = Entry::find()
				->slug($segment)
				->level($level)
				->site($language);
			if ($criteria->count() < 1) {
				$allEntries = false;
			}
			$level++;
		}

		return $allEntries;
	}
}
