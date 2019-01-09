<?php

namespace mutation\redirectlanguage;

use craft\base\Plugin;

class RedirectLanguage extends Plugin
{
    CONST COOKIE_NAME = 'craft_site_id';

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

        $current_path = trim($_SERVER['REQUEST_URI'], '/');

        foreach (\Craft::$app->i18n->getSiteLocales() as $locale) {
            $available_languages[] = $locale->id;
        }

        $sites_list_ids = [];
        $sites_list = \Craft::$app->sites->getAllSites();
        foreach ($sites_list as $sites) {
            $sites_list_ids[] = $sites->id;
        }

        $current_site = \Craft::$app->sites->getCurrentSite();
        $current_site_url = $current_site->getBaseUrl();
        $current_site_path = trim(parse_url($current_site_url)["path"], '/');

        if ($current_site_path === $current_path) {
            setcookie(self::COOKIE_NAME, $current_site->id, 0, '/');
            return;
        }

        if ($current_path !== '') {
            return;
        }

        $cookie_site_id = $_COOKIE[self::COOKIE_NAME] ?? '';

        if (\in_array($cookie_site_id, $sites_list_ids)) {
            $cookie_site = \Craft::$app->sites->getSiteById($cookie_site_id);
            \Craft::$app->response->redirect($cookie_site->getBaseUrl())->send();
            return;
        }

        $accepted = $this->parse_language_list($_SERVER['HTTP_ACCEPT_LANGUAGE']);
        $available = $this->parse_language_list(implode(',', $available_languages));
        $matches = $this->find_matches($accepted, $available);
        $matches_values = array_values($matches);
        $first_match = array_shift($matches_values);

        $browser_lang = count($first_match) > 0 ? $first_match[0] : null;

        $browser_site = null;
        if ($browser_lang === null) {
            $browser_site = \Craft::$app->sites->getPrimarySite();
        } else {
            foreach ($sites_list as $site) {
                if (strtolower($site->language) === $browser_lang) {
                    $browser_site = $site;
                    break;
                }
            }
        }

        \Craft::$app->response->redirect($browser_site->getBaseUrl())->send();
    }

    private function parse_language_list($languageList)
    {
        if ($languageList === null) {
            if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
                return array();
            }
            $languageList = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
        }
        $languages = array();
        $languageRanges = explode(',', trim($languageList));
        foreach ($languageRanges as $languageRange) {
            if (preg_match('/(\*|[a-zA-Z0-9]{1,8}(?:-[a-zA-Z0-9]{1,8})*)(?:\s*;\s*q\s*=\s*(0(?:\.\d{0,3})|1(?:\.0{0,3})))?/', trim($languageRange), $match)) {
                if (!isset($match[2])) {
                    $match[2] = '1.0';
                } else {
                    $match[2] = (string)(float)$match[2];
                }
                if (!isset($languages[$match[2]])) {
                    $languages[$match[2]] = array();
                }
                $languages[$match[2]][] = strtolower($match[1]);
            }
        }
        krsort($languages);
        return $languages;
    }

    private function find_matches($accepted, $available)
    {
        $matches = array();
        $any = false;
        foreach ($accepted as $acceptedQuality => $acceptedValues) {
            $acceptedQuality = (float)$acceptedQuality;
            if ($acceptedQuality === 0.0) {
                continue;
            }
            foreach ($available as $availableQuality => $availableValues) {
                $availableQuality = (float)$availableQuality;
                if ($availableQuality === 0.0) {
                    continue;
                }
                foreach ($acceptedValues as $acceptedValue) {
                    if ($acceptedValue === '*') {
                        $any = true;
                    }
                    foreach ($availableValues as $availableValue) {
                        $matchingGrade = $this->match_language($acceptedValue, $availableValue);
                        if ($matchingGrade > 0) {
                            $q = (string)($acceptedQuality * $availableQuality * $matchingGrade);
                            if (!isset($matches[$q])) {
                                $matches[$q] = array();
                            }
                            if (!in_array($availableValue, $matches[$q])) {
                                $matches[$q][] = $availableValue;
                            }
                        }
                    }
                }
            }
        }
        if (count($matches) === 0 && $any) {
            $matches = $available;
        }
        krsort($matches);
        return $matches;
    }

    private function match_language($a, $b)
    {
        $a = explode('-', $a);
        $b = explode('-', $b);
        for ($i = 0, $n = min(count($a), count($b)); $i < $n; $i++) {
            if ($a[$i] !== $b[$i]) break;
        }
        return $i === 0 ? 0 : (float)$i / count($a);
    }
}
