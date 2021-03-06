<?php namespace Vsch\TranslationManager;

use Illuminate\Routing\Controller as BaseController;
use Vsch\TranslationManager\Models\Translation;
use Vsch\TranslationManager\Models\UserLocales;
use Vsch\TranslationManager\Repositories\Interfaces\ITranslatorRepository;

include_once(__DIR__ . '/Support/finediff.php');

class Controller extends BaseController
{
    const COOKIE_LANG_LOCALE = 'lang';
    const COOKIE_TRANS_LOCALE = 'trans';
    const COOKIE_PRIM_LOCALE = 'prim';
    const COOKIE_DISP_LOCALES = 'disp';
    const COOKIE_SHOW_USAGE = 'show-usage';
    const COOKIE_CONNECTION_NAME = 'connection-name';
    const COOKIE_TRANS_FILTERS = 'trans-filters';
    /** @var \Vsch\TranslationManager\Manager */
    protected $manager;
    protected $packagePrefix;
    protected $package;
    protected $sqltraces;
    protected $logSql;
    protected $connectionList;
    protected $userLocales;
    private $cookiePrefix;
    private $primaryLocale;
    private $translatingLocale;
    private $displayLocales;
    private $showUsageInfo;
    private $locales;
    private $translatorRepository;
    // list of locales that the user is allowed to modify
    private $transFilters;

    public function __construct(ITranslatorRepository $translatorRepository)
    {
        $this->package = \Vsch\TranslationManager\ManagerServiceProvider::PACKAGE;
        $this->packagePrefix = $this->package . '::';
        $this->manager = \App::make($this->package);
        $this->translatorRepository = $translatorRepository;

        $this->connectionList = [];
        $this->connectionList[''] = 'default';
        $connections = $this->manager->config(Manager::DB_CONNECTIONS_KEY);
        if ($connections && array_key_exists(\App::environment(), $connections)) {
            foreach ($connections[\App::environment()] as $key => $value) {
                if (array_key_exists('description', $value)) {
                    $this->connectionList[$key] = $value['description'];
                } else {
                    $this->connectionList[$key] = $key;
                }
            }
        }

        $this->cookiePrefix = $this->manager->config('persistent_prefix', 'K9N6YPi9WHwKp6E3jGbx');

        $this->middleware(function ($request, $next) {
            $this->initialize();
            return $next($request);
        });
    }

    private function initialize()
    {
        $connectionName = \Cookie::has($this->cookieName(self::COOKIE_CONNECTION_NAME)) ? \Cookie::get($this->cookieName(self::COOKIE_CONNECTION_NAME)) : '';
        $this->setConnectionName($connectionName);

        $locale = \Cookie::get($this->cookieName(self::COOKIE_LANG_LOCALE), \Lang::getLocale());
        \App::setLocale($locale);
        $this->primaryLocale = \Cookie::get($this->cookieName(self::COOKIE_PRIM_LOCALE), $this->manager->config('primary_locale', 'en'));

        $this->locales = $this->loadLocales();
        $locales = $this->locales;

        if ((!\Gate::allows(Manager::ABILITY_ADMIN_TRANSLATIONS)) && $this->manager->areUserLocalesEnabled()) {
            // see what locales are available for this user
            $userId = \Auth::id();
            if ($userId !== null) {
                $userLocale = new UserLocales();
                $userLocale->setConnection($connectionName);
                $userLocales = $userLocale->query()->where('user_id', $userId)->first();
                if ($userLocales && trim($userLocales->locales)) {
                    $locales = explode(',', $userLocales->locales);
                }
            }
        }

        $packedLocales = implode(',', array_intersect($this->locales, $locales));
        $this->userLocales = $packedLocales ? ',' . $packedLocales . ',' : '';

        $this->translatingLocale = \Cookie::get($this->cookieName(self::COOKIE_TRANS_LOCALE));
        $this->showUsageInfo = \Cookie::get($this->cookieName(self::COOKIE_SHOW_USAGE));
        $this->transFilters = \Cookie::get($this->cookieName(self::COOKIE_TRANS_FILTERS), ['filter' => 'show-all', 'regex' => '']);

        if (!$this->translatingLocale || ($this->translatingLocale === $this->primaryLocale && count($this->locales) > 1)) {
            $this->translatingLocale = count($this->locales) > 1 ? $this->locales[1] : $this->locales[0];
            \Cookie::queue($this->cookieName(self::COOKIE_TRANS_LOCALE), $this->translatingLocale, 60 * 24 * 365 * 1);
        }

        $this->displayLocales = \Cookie::has($this->cookieName(self::COOKIE_DISP_LOCALES)) ? \Cookie::get($this->cookieName(self::COOKIE_DISP_LOCALES)) : implode(',', array_slice($this->locales, 0, 5));
        $this->displayLocales .= implode(',', array_flatten(array_unique(explode(',', ($this->displayLocales ? ',' : '') . $this->primaryLocale . ',' . $this->translatingLocale))));

        //$this->sqltraces = [];
        //$this->logSql = 0;
        //
        //$thisController = $this;
        //\Event::listen('illuminate.query', function ($query, $bindings, $time, $name) use ($thisController)
        //{
        //    if ($thisController->logSql)
        //    {
        //        $thisController->sqltraces[] = ['query' => $query, 'bindings' => $bindings, 'time' => $time];
        //    }
        //});
    }

    public function cookieName($cookie)
    {
        return $this->cookiePrefix . $cookie;
    }

    public function setConnectionName($connection)
    {
        if (!array_key_exists($connection, $this->connectionList)) $connection = '';
        \Cookie::queue($this->cookieName(self::COOKIE_CONNECTION_NAME), $connection, 60 * 24 * 365 * 1);
        $this->manager->setConnectionName($connection);
    }

    protected function loadLocales()
    {
        //Set the default locale as the first one.
        $currentLocale = \Config::get('app.locale');
        $primaryLocale = $this->primaryLocale;
        if (!$currentLocale) {
            $currentLocale = $primaryLocale;
        }

        $translatingLocale = \Cookie::get($this->cookieName(self::COOKIE_TRANS_LOCALE), $currentLocale);
        $locales = ManagerServiceProvider::getLists($this->getTranslation()->groupBy('locale')->pluck('locale')) ?: [];

        // limit the locale list to what is in the config
        $configShowLocales = $this->manager->config(Manager::SHOW_LOCALES_KEY, []);
        if ($configShowLocales) {
            if (!is_array($configShowLocales)) $configShowLocales = array($configShowLocales);
            $locales = array_intersect($locales, $configShowLocales);
        }

        $configLocales = $this->manager->config(Manager::ADDITIONAL_LOCALES_KEY, []);
        if (!is_array($configLocales)) $configLocales = array($configLocales);

        $locales = array_merge(array($primaryLocale, $translatingLocale, $currentLocale), $configLocales, $locales);
        return array_flatten(array_unique($locales));
    }

    protected function getTranslation()
    {
        return $this->manager->getTranslation();
    }

    public static function routes()
    {
        \Route::get('view/{group?}', '\\Vsch\\TranslationManager\\Controller@getView');

        //deprecated: \Route::controller('admin/translations', '\\Vsch\\TranslationManager\\Controller');
        \Route::get('/', '\\Vsch\\TranslationManager\\Controller@getIndex');
        \Route::get('connection', '\\Vsch\\TranslationManager\\Controller@getConnection');
        \Route::get('import', '\\Vsch\\TranslationManager\\Controller@getImport');
        \Route::get('index', '\\Vsch\\TranslationManager\\Controller@getIndex');
        \Route::get('interface_locale', '\\Vsch\\TranslationManager\\Controller@getInterfaceLocale');
        \Route::get('keyop/{group}/{op?}', '\\Vsch\\TranslationManager\\Controller@getKeyop');
        \Route::get('publish/{group}', '\\Vsch\\TranslationManager\\Controller@getPublish');
        \Route::get('search', '\\Vsch\\TranslationManager\\Controller@getSearch');
        \Route::get('toggle_in_place_edit', '\\Vsch\\TranslationManager\\Controller@getToggleInPlaceEdit');
        \Route::get('trans_filters', '\\Vsch\\TranslationManager\\Controller@getTransFilters');
        \Route::get('translation', '\\Vsch\\TranslationManager\\Controller@getTranslation');
        \Route::get('usage_info', '\\Vsch\\TranslationManager\\Controller@getUsageInfo');
        \Route::get('view', '\\Vsch\\TranslationManager\\Controller@getView');
        \Route::get('zipped_translations/{group?}', '\\Vsch\\TranslationManager\\Controller@getZippedTranslations');
        \Route::post('add/{group}', '\\Vsch\\TranslationManager\\Controller@postAdd');
        \Route::post('copy_keys/{group}', '\\Vsch\\TranslationManager\\Controller@postCopyKeys');
        \Route::post('delete/{group}/{key}', '\\Vsch\\TranslationManager\\Controller@postDelete');
        \Route::post('delete_all/{group}', '\\Vsch\\TranslationManager\\Controller@postDeleteAll');
        \Route::post('delete_keys/{group}', '\\Vsch\\TranslationManager\\Controller@postDeleteKeys');
        \Route::post('delete_suffixed_keys{group?}', '\\Vsch\\TranslationManager\\Controller@postDeleteSuffixedKeys');
        \Route::post('edit/{group}', '\\Vsch\\TranslationManager\\Controller@postEdit');
        \Route::post('find', '\\Vsch\\TranslationManager\\Controller@postFind');
        \Route::post('import/{group}', '\\Vsch\\TranslationManager\\Controller@postImport');
        \Route::post('move_keys/{group}', '\\Vsch\\TranslationManager\\Controller@postMoveKeys');
        \Route::post('preview_keys/{group}', '\\Vsch\\TranslationManager\\Controller@postPreviewKeys');
        \Route::post('publish/{group}', '\\Vsch\\TranslationManager\\Controller@postPublish');
        \Route::post('show_source/{group}/{key}', '\\Vsch\\TranslationManager\\Controller@postShowSource');
        \Route::post('undelete/{group}/{key}', '\\Vsch\\TranslationManager\\Controller@postUndelete');
        \Route::post('user_locales', '\\Vsch\\TranslationManager\\Controller@postUserLocales');
        \Route::post('yandex_key', '\\Vsch\\TranslationManager\\Controller@postYandexKey');
    }

    /**
     * @return mixed
     */
    public static function active($url)
    {
        $url = url($url, null, false);
        $url = str_replace('https:', 'http:', $url);
        $req = str_replace('https:', 'http:', \Request::url());
        $ret = ($pos = strpos($req, $url)) === 0 && (strlen($req) === strlen($url) || substr($req, strlen($url), 1) === '?' || substr($req, strlen($url), 1) === '#');
        return $ret;
    }

    public function getSearch()
    {
        $q = \Request::get('q');

        if ($q === '') $translations = [];
        else {
            $displayWhere = $this->displayLocales ? ' AND locale IN (\'' . implode("','", explode(',', $this->displayLocales)) . "')" : '';

            if (strpos($q, '%') === false) $q = "%$q%";

            // need to fill-in missing locale's that match the key
            $translations = $this->translatorRepository->searchByRequest($q, $displayWhere);
        }

        $numTranslations = count($translations);

        return \View::make($this->packagePrefix . 'search')
            ->with('controller', ManagerServiceProvider::CONTROLLER_PREFIX . get_class($this))
            ->with('userLocales', $this->userLocales)
            ->with('package', $this->package)
            ->with('translations', $translations)
            ->with('numTranslations', $numTranslations);
    }

    protected function getConnection()
    {
        $connection = $this->manager->getConnection();
        return $connection;
    }

    public function getView($group = null)
    {
        return $this->getIndex($group);
    }

    public function getIndex($group = null)
    {
        try {
            return $this->processIndex($group);
        } catch (\Exception $e) {
            // if have non default connection, reset it
            if ($this->getConnectionName()) {
                $this->setConnectionName('');
            }
        }
        return $this->processIndex($group);
    }

    private function processIndex($group = null)
    {
        $locales = $this->locales;
        $currentLocale = \Lang::getLocale();
        $primaryLocale = $this->primaryLocale;
        $translatingLocale = $this->translatingLocale;

        $groups = array('' => noEditTrans($this->packagePrefix . 'messages.choose-group')) + $this->manager->getGroupList();

        if ($group != null && !array_key_exists($group, $groups)) {
            return \Redirect::action(ManagerServiceProvider::CONTROLLER_PREFIX . get_class($this) . '@getIndex');
        }

        $numChanged = $this->getTranslation()->where('group', $group)->where('status', Translation::STATUS_CHANGED)->count();

        // to allow proper handling of nested directory structure we need to copy the keys for the group for all missing
        // translations, otherwise we don't know what the group and key looks like.
        //$allTranslations = $this->getTranslation()->where('group', $group)->orderBy('key', 'asc')->get();
        $allTranslations = $this->translatorRepository->allTranslations($group, $this->displayLocales);

        $numTranslations = count($allTranslations);
        $translations = array();
        foreach ($allTranslations as $translation) {
            $translations[$translation->key][$translation->locale] = $translation;
        }

        $this->manager->cacheGroupTranslations($group, $this->displayLocales, $translations);

        $stats = $this->translatorRepository->stats($this->displayLocales);

        // returned result set lists missing, changed, group, locale
        $summary = [];
        foreach ($stats as $stat) {
            if (!isset($summary[$stat->group])) {
                $item = $summary[$stat->group] = new \stdClass();
                $item->missing = '';
                $item->changed = '';
                $item->cached = '';
                $item->deleted = '';
                $item->group = $stat->group;
            }

            $item = $summary[$stat->group];
            if ($stat->missing) {
                $item->missing .= $stat->locale . ":" . $stat->missing . " ";
            }

            if ($stat->changed) {
                $item->changed .= $stat->locale . ":" . $stat->changed . " ";
            }

            if ($stat->cached) {
                $item->cached .= $stat->locale . ":" . $stat->cached . " ";
            }

            if ($stat->deleted) {
                $item->deleted .= $stat->locale . ":" . $stat->deleted . " ";
            }
        }

        $mismatches = null;
        $mismatchEnabled = $this->manager->config('mismatch_enabled');

        if ($mismatchEnabled) {
            // get mismatches
            $mismatches = $this->translatorRepository->findMismatches($this->displayLocales, $primaryLocale, $translatingLocale);

            $key = '';
            $translatingList = [];
            $primaryList = [];
            $translatingBases = [];    // by key
            $primaryBases = [];    // by key
            $extra = new \stdClass();
            $extra->key = '';
            $mismatches[] = $extra;
            foreach ($mismatches as $mismatch) {
                if ($mismatch->key !== $key) {
                    if ($key) {
                        // process diff for key
                        $translatingText = '';
                        $primaryText = '';
                        if (count($primaryList) > 1) {
                            $maxPrimary = 0;
                            foreach ($primaryList as $en => $count) {
                                if ($maxPrimary < $count) {
                                    $maxPrimary = $count;
                                    $primaryText = $en;
                                }
                            }
                            $primaryBases[$key] = $primaryText;
                        } else {
                            $primaryText = array_keys($primaryList)[0];
                            $primaryBases[$key] = $primaryText;
                        }
                        if (count($translatingList) > 1) {
                            $maxTranslating = 0;
                            foreach ($translatingList as $ru => $count) {
                                if ($maxTranslating < $count) {
                                    $maxTranslating = $count;
                                    $translatingText = $ru;
                                }
                            }
                            $translatingBases[$key] = $translatingText;
                        } else {
                            $translatingText = array_keys($translatingList)[0];
                            $translatingBases[$key] = $translatingText;
                        }
                    }
                    $key = $mismatch->key;
                    $translatingList = [];
                    $primaryList = [];
                }

                if ($mismatch->key === '') break;

                if (!isset($primaryList[$mismatch->en])) {
                    $primaryList[$mismatch->en] = 1;
                } else {
                    $primaryList[$mismatch->en]++;
                }

                if (!isset($translatingList[$mismatch->ru])) {
                    $translatingList[$mismatch->ru] = 1;
                } else {
                    $translatingList[$mismatch->ru]++;
                }
            }

            array_splice($mismatches, count($mismatches) - 1, 1);

            foreach ($mismatches as $mismatch) {
                $mismatch->en_value = $mismatch->ru;
                $mismatch->en = mb_renderDiffHtml($primaryBases[$mismatch->key], $mismatch->en);
                $mismatch->ru_value = $mismatch->ru;
                $mismatch->ru = mb_renderDiffHtml($translatingBases[$mismatch->key], $mismatch->ru);
            }
        }

        // returned result set lists group key ru, en columns for the locale translations, ru has different values for same values in en
        $displayLocales = array_intersect($locales, explode(',', $this->displayLocales));

        // need to put display locales first in the $locales list
        $locales = array_merge($displayLocales, array_diff($locales, $displayLocales));

        $displayLocales = array_combine($displayLocales, $displayLocales);

        $show_usage_enabled = $this->manager->config('log_key_usage_info', false);

        $userList = [];
        $admin_translations = \Gate::allows(Manager::ABILITY_ADMIN_TRANSLATIONS);
        if ($admin_translations && $this->manager->areUserLocalesEnabled()) {
            $connection_name = $this->getConnectionName();
            $userListProvider = $this->manager->getUserListProvider($connection_name);
            if ($userListProvider !== null && is_a($userListProvider, "Closure")) {
                $userList = null;
                $haveUsers = $userListProvider(\Auth::user(), $this->manager->getUserListConnection($connection_name), $userList);
                if ($haveUsers && is_array($userList)) {
                    /* @var $connection_name string */
                    /* @var $query  \Illuminate\Database\Eloquent\Builder */
                    $userLocalesModel = new UserLocales();
                    $userLocaleList = $userLocalesModel->on($connection_name)->get();

                    $userLocales = [];
                    foreach ($userLocaleList as $userLocale) {
                        $userLocales[$userLocale->user_id] = $userLocale;
                    }

                    foreach ($userList as $user) {
                        if (array_key_exists($user->id, $userLocales)) {
                            $user->locales = $userLocales[$user->id]->locales;
                        } else {
                            $user->locales = '';
                        }
                    }
                } else {
                    $userList = [];
                }
            }
        }

        $adminEnabled = $this->manager->config('admin_enabled') &&
            \Gate::allows(Manager::ABILITY_ADMIN_TRANSLATIONS);

        $userLocalesEnabled = $this->manager->areUserLocalesEnabled() && $userList;

        return \View::make($this->packagePrefix . 'index')
            ->with('controller', ManagerServiceProvider::CONTROLLER_PREFIX . get_class($this))
            ->with('package', $this->package)
            ->with('public_prefix', ManagerServiceProvider::PUBLIC_PREFIX)
            ->with('translations', $translations)
            ->with('yandex_key', !!$this->manager->config('yandex_translator_key'))
            ->with('locales', $locales)
            ->with('primaryLocale', $primaryLocale)
            ->with('currentLocale', $currentLocale)
            ->with('translatingLocale', $translatingLocale)
            ->with('displayLocales', $displayLocales)
            ->with('groups', $groups)
            ->with('group', $group)
            ->with('numTranslations', $numTranslations)
            ->with('numChanged', $numChanged)
            ->with('adminEnabled', $adminEnabled)
            ->with('mismatchEnabled', $mismatchEnabled)
            ->with('userLocalesEnabled', $userLocalesEnabled)
            ->with('stats', $summary)
            ->with('mismatches', $mismatches)
            ->with('show_usage', $this->showUsageInfo && $show_usage_enabled)
            ->with('usage_info_enabled', $show_usage_enabled)
            ->with('connection_list', $this->connectionList)
            ->with('transFilters', $this->transFilters)
            ->with('userLocales', $this->userLocales)
            ->with('markdownKeySuffix', $this->manager->config(Manager::MARKDOWN_KEY_SUFFIX))
            ->with('userList', $userList)
            ->with('connection_name', ($this->manager->isDefaultTranslationConnection($this->getConnectionName()) ? '' : $this->getConnectionName()));
    }

    public function getConnectionName()
    {
        return $this->manager->getConnectionName();
    }

    public function postAdd($group)
    {
        if (\Gate::allows(Manager::ABILITY_ADMIN_TRANSLATIONS)) {
            $keys = explode("\n", trim(\Request::get('keys')));
            $suffixes = explode("\n", trim(\Request::get('suffixes')));
            $group = explode('::', $group, 2);
            $namespace = '*';
            if (count($group) > 1) $namespace = array_shift($group);
            $group = $group[0];

            foreach ($keys as $key) {
                $key = trim($key);
                if ($group && $key) {
                    if ($suffixes) {
                        foreach ($suffixes as $suffix) {
                            $this->manager->missingKey($namespace, $group, $key . trim($suffix), null, false, false);
                        }
                    } else {
                        $this->manager->missingKey($namespace, $group, $key, null, false, false);
                    }
                }
            }
        }
        //Session::flash('_old_data', \Request::except('keys'));
        return \Redirect::back()->withInput();
    }

    public function postDeleteSuffixedKeys($group)
    {
        if (\Gate::allows(Manager::ABILITY_ADMIN_TRANSLATIONS)) {
            if (!in_array($group, $this->manager->config(Manager::EXCLUDE_GROUPS_KEY)) && $this->manager->config('admin_enabled')) {
                $keys = explode("\n", trim(\Request::get('keys')));
                $suffixes = explode("\n", trim(\Request::get('suffixes')));

                if (count($suffixes) === 1 && $suffixes[0] === '') $suffixes = [];

                foreach ($keys as $key) {
                    $key = trim($key);
                    if ($group && $key) {
                        if ($suffixes) {
                            foreach ($suffixes as $suffix) {
                                //$this->getTranslation()->where('group', $group)->where('key', $key . trim($suffix))->delete();
                                $result = $this->translatorRepository->updateIsDeletedByGroupAndKey($group, $key . trim($suffix), 1);
                            }
                        } else {
                            //$this->getTranslation()->where('group', $group)->where('key', $key)->delete();
                            $result = $this->translatorRepository->updateIsDeletedByGroupAndKey($group, $key, 1);
                        }
                    }
                }
            }
        }
        return \Redirect::back()->withInput();
    }

    public function postEdit($group)
    {
        if (!in_array($group, $this->manager->config(Manager::EXCLUDE_GROUPS_KEY))) {
            $name = \Request::get('name');
            $value = \Request::get('value');

            list($locale, $key) = explode('|', $name, 2);
            if ($this->isLocaleEnabled($locale)) {
                $translation = $this->manager->firstOrNewTranslation(array(
                    'locale' => $locale,
                    'group'  => $group,
                    'key'    => $key,
                ));

                $markdownSuffix = $this->manager->config(Manager::MARKDOWN_KEY_SUFFIX);
                $isMarkdownKey = $markdownSuffix != '' && ends_with($key, $markdownSuffix) && $key !== $markdownSuffix;

                if (!$isMarkdownKey) {
                    // strip off trailing spaces and eol's and &nbsps; that seem to be added when multiple spaces are entered in the x-editable textarea
                    $value = trim(str_replace("\xc2\xa0", ' ', $value));
                }

                $value = $value !== '' ? $value : null;

                $translation->value = $value;
                $translation->status = (($translation->isDirty() && $value != $translation->saved_value) ? Translation::STATUS_CHANGED : Translation::STATUS_SAVED);
                $translation->save();

                if ($isMarkdownKey) {
                    $markdownKey = $key;
                    $markdownValue = $value;

                    $key = substr($markdownKey, 0, -strlen($markdownSuffix));

                    $translation = $this->manager->firstOrNewTranslation(array(
                        'locale' => $locale,
                        'group'  => $group,
                        'key'    => $key,
                    ));

                    $value = $markdownValue !== null ? \Markdown::convertToHtml(str_replace("\xc2\xa0", ' ', $markdownValue)) : null;

                    $translation->value = $value;
                    $translation->status = (($translation->isDirty() && $value != $translation->saved_value) ? Translation::STATUS_CHANGED : Translation::STATUS_SAVED);
                    $translation->save();
                }
            }
        }
        return array('status' => 'ok');
    }

    public function isLocaleEnabled($locale)
    {
        if (!is_array($locale)) $locale = array($locale);
        foreach ($locale as $item) {
            if (!str_contains($this->userLocales, ',' . $item . ',')) return false;
        }
        return true;
    }

    public function postDelete($group, $key)
    {
        if (\Gate::allows(Manager::ABILITY_ADMIN_TRANSLATIONS)) {
            $key = decodeKey($key);
            if (!in_array($group, $this->manager->config(Manager::EXCLUDE_GROUPS_KEY)) && $this->manager->config('admin_enabled')) {
                //$this->getTranslation()->where('group', $group)->where('key', $key)->delete();
                $result = $this->translatorRepository->updateIsDeletedByGroupAndKey($group, $key, 1);
            }
        }
        return array('status' => 'ok');
    }

    public function postShowSource($group, $key)
    {
        $key = decodeKey($key);
        $results = '';
        if (!in_array($group, $this->manager->config(Manager::EXCLUDE_GROUPS_KEY)) && $this->manager->config('admin_enabled')) {
            $result = $this->translatorRepository->selectSourceByGroupAndKey($group, $key);

            foreach ($result as $item) {
                $results .= $item->source;
            }
        }

        foreach (explode("\n", $results) as $ref) {
            if ($ref != '') {
                $refs[] = $ref;
            }
        }
        sort($refs);
        return array('status' => 'ok', 'result' => $refs, 'key_name' => "$group.$key");
    }

    public function postUndelete($group, $key)
    {
        if (\Gate::allows(Manager::ABILITY_ADMIN_TRANSLATIONS)) {
            $key = decodeKey($key);
            if (!in_array($group, $this->manager->config(Manager::EXCLUDE_GROUPS_KEY)) && $this->manager->config('admin_enabled')) {
                //$this->getTranslation()->where('group', $group)->where('key', $key)->delete();
                $result = $this->translatorRepository->updateIsDeletedByGroupAndKey($group, $key, 0);
            }
        }
        return array('status' => 'ok');
    }

    public function getKeyop($group, $op = 'preview')
    {
        return $this->keyOp($group, $op);
    }

    protected function keyOp($group, $op = 'preview')
    {
        $errors = [];
        $keymap = [];
        $this->logSql = 1;
        $this->sqltraces = [];
        $userLocales = $this->userLocales;
        if ($userLocales) $userLocales = "'" . str_replace(',', "','", substr($userLocales, 1, -1)) . "'";

        if ($userLocales && !in_array($group, $this->manager->config(Manager::EXCLUDE_GROUPS_KEY)) && $this->manager->config('admin_enabled')) {
            $srckeys = explode("\n", trim(\Request::get('srckeys')));
            $dstkeys = explode("\n", trim(\Request::get('dstkeys')));

            array_walk($srckeys, function (&$val, $key) use (&$srckeys) {
                $val = trim($val);
                if ($val === '') unset($srckeys[$key]);
            });

            array_walk($dstkeys, function (&$val, $key) use (&$dstkeys) {
                $val = trim($val);
                if ($val === '') unset($dstkeys[$key]);
            });

            if (!$group) {
                $errors[] = trans($this->packagePrefix . 'messages.keyop-need-group');
            } elseif (count($srckeys) !== count($dstkeys) && ($op === 'copy' || $op === 'move' || count($dstkeys))) {
                $errors[] = trans($this->packagePrefix . 'messages.keyop-count-mustmatch');
            } elseif (!count($srckeys)) {
                $errors[] = trans($this->packagePrefix . 'messages.keyop-need-keys');
            } else {
                if (!count($dstkeys)) {
                    $dstkeys = array_fill(0, count($srckeys), null);
                }

                $keys = array_combine($srckeys, $dstkeys);
                $hadErrors = false;

                foreach ($keys as $src => $dst) {
                    $keyerrors = [];

                    if ($dst !== null) {

                        if ((substr($src, 0, 1) === '*') !== (substr($dst, 0, 1) === '*')) {
                            $keyerrors[] = trans($this->packagePrefix . 'messages.keyop-wildcard-mustmatch');
                        }

                        if ((substr($src, -1, 1) === '*') !== (substr($dst, -1, 1) === '*')) {
                            $keyerrors[] = trans($this->packagePrefix . 'messages.keyop-wildcard-mustmatch');
                        }

                        if ((substr($src, 0, 1) === '*') && (substr($src, -1, 1) === '*')) {
                            $keyerrors[] = trans($this->packagePrefix . 'messages.keyop-wildcard-once');
                        }
                    }

                    if (!empty($keyerrors)) {
                        $hadErrors = true;
                        $keymap[$src] = ['errors' => $keyerrors, 'dst' => $dst,];
                        continue;
                    }

                    list($srcgrp, $srckey) = self::keyGroup($group, $src);
                    list($dstgrp, $dstkey) = $dst === null ? [null, null] : self::keyGroup($group, $dst);

                    $rows = $this->translatorRepository->selectKeys($src, $dst, $userLocales, $srcgrp, $srckey, $dstkey, $dstgrp);

                    $keymap[$src] = ['dst' => $dst, 'rows' => $rows];
                }

                if (!$hadErrors && ($op === 'copy' || $op === 'move' || $op === 'delete')) {
                    foreach ($keys as $src => $dst) {

                        $rows = $keymap[$src]['rows'];

                        $rowids = array_reduce($rows, function ($carry, $row) {
                            return $carry . ',' . $row->id;
                        }, '');
                        $rowids = substr($rowids, 1);

                        list($srcgrp, $srckey) = self::keyGroup($group, $src);
                        if ($op === 'move') {
                            foreach ($rows as $row) {
                                if ($this->isLocaleEnabled($row->locale)) {
                                    list($dstgrp, $dstkey) = self::keyGroup($row->dstgrp, $row->dst);
                                    $to_delete = $this->translatorRepository->selectToDeleteTranslations($dstgrp, $dstkey, $row->locale, $rowids);

                                    if (!empty($to_delete)) {
                                        $to_delete = $to_delete[0]->ids;
                                        if ($to_delete) {
                                            //$this->getConnection()->update("UPDATE ltm_translations SET is_deleted = 1 WHERE id IN ($to_delete)");
                                            // have to delete right away, we will be bringing another key here
                                            // TODO: copy value to new key's saved value
                                            $this->translatorRepository->deleteTranslationsForIds($to_delete);
                                        }
                                    }

                                    $this->translatorRepository->updateGroupKeyStatusById($dstgrp, $dstkey, $row->id);
                                }
                            }
                        } elseif ($op === 'delete') {
                            $this->translatorRepository->updateIsDeletedByIds($rowids);
                        } elseif ($op === 'copy') {
                            // TODO: split operation into update and insert so that conflicting keys get new values instead of being replaced
                            foreach ($rows as $row) {
                                if ($this->isLocaleEnabled($row->locale)) {
                                    list($dstgrp, $dstkey) = self::keyGroup($row->dstgrp, $row->dst);
                                    $to_delete = $this->translatorRepository->selectToDeleteTranslations($dstgrp, $dstkey, $userLocales, $rowids);

                                    if (!empty($to_delete)) {
                                        $to_delete = $to_delete[0]->ids;
                                        if ($to_delete) {
                                            //$this->getConnection()->update("UPDATE ltm_translations SET is_deleted = 1 WHERE id IN ($to_delete)");
                                            $this->translatorRepository->deleteTranslationsForIds($to_delete);
                                        }
                                    }

                                    $this->translatorRepository->copyKeys($dstgrp, $dstkey, $row->id);
                                }
                            }
                        }
                    }
                }
            }
        } else {
            $errors[] = trans($this->packagePrefix . 'messages.keyops-not-authorized');
        }

        $this->logSql = 0;
        return \View::make($this->packagePrefix . 'keyop')
            ->with('controller', ManagerServiceProvider::CONTROLLER_PREFIX . get_class($this))
            ->with('package', $this->package)
            ->with('errors', $errors)
            ->with('keymap', $keymap)
            ->with('op', $op)
            ->with('group', $group);
    }

    protected static function keyGroup($group, $key)
    {
        $prefix = '';
        $gkey = $key;

        if (starts_with($key, 'vnd:') || starts_with($key, 'wbn:')) {
            // these have vendor with . afterwards in the group
            $parts = explode('.', $key, 2);

            if (count($parts) === 2) {
                $prefix = $parts[0] . '.';
                $gkey = $parts[1];
            }
        }

        $parts = explode('.', $gkey, 2);

        if (count($parts) === 1) {
            $tgroup = $group;
            $tkey = $gkey;
        } else {
            $tgroup = $parts[0];
            $tkey = $parts[1];
        }

        return [$prefix . $tgroup, $tkey];
    }

    public function postCopyKeys($group)
    {
        return $this->keyOp($group, 'copy');
    }

    public function postMoveKeys($group)
    {
        return $this->keyOp($group, 'move');
    }

    public function postDeleteKeys($group)
    {
        return $this->keyOp($group, 'delete');
    }

    public function postPreviewKeys($group)
    {
        return $this->keyOp($group, 'preview');
    }

    public function postImport($group)
    {
        $replace = \Request::get('replace', false);
        $counter = $this->manager->importTranslations($group === '*' ? $replace : ($this->manager->inDatabasePublishing() == 1 ? 0 : 1)
            , $group === '*' ? null : [$group]);
        return \Response::json(array('status' => 'ok', 'counter' => $counter));
    }

    public function getImport()
    {
        $replace = \Request::get('replace', false);
        $group = \Request::get('group', '*');
        $this->manager->clearErrors();
        $counter = $this->manager->importTranslations($group === '*' ? $replace : ($this->manager->inDatabasePublishing() == 1 ? 0 : 1)
            , $group === '*' ? null : [$group]);
        $errors = $this->manager->errors();
        return \Response::json(array('status' => 'ok', 'counter' => $counter, 'errors' => $errors));
    }

    public function postFind()
    {
        $numFound = $this->manager->findTranslations();

        return \Response::json(array('status' => 'ok', 'counter' => (int)$numFound));
    }

    public function postDeleteAll($group)
    {
        $this->manager->truncateTranslations($group);

        return \Response::json(array('status' => 'ok', 'counter' => (int)0));
    }

    public function getPublish($group)
    {
        $this->manager->exportTranslations($group);

        return \Response::json(array('status' => 'ok'));
    }

    public function postPublish($group)
    {
        $this->manager->exportTranslations($group);
        $errors = $this->manager->errors();

        return \Response::json(array('status' => $errors ? 'errors' : 'ok', 'errors' => $errors));
    }

    public function getToggleInPlaceEdit()
    {
        inPlaceEditing(!inPlaceEditing());
        if (\App::runningUnitTests()) return \Redirect::to('/');
        return !is_null(\Request::header('referer')) ? \Redirect::back() : \Redirect::to('/');
    }

    public function getInterfaceLocale()
    {
        $locale = \Request::get("l");
        $translating = \Request::get("t");
        $primary = \Request::get("p");
        $connection = \Request::get("c");
        $displayLocales = \Request::get("d");
        $display = implode(',', $displayLocales ?: []);

        \App::setLocale($locale);
        \Cookie::queue($this->cookieName(self::COOKIE_LANG_LOCALE), $locale, 60 * 24 * 365 * 1);
        \Cookie::queue($this->cookieName(self::COOKIE_TRANS_LOCALE), $translating, 60 * 24 * 365 * 1);
        \Cookie::queue($this->cookieName(self::COOKIE_PRIM_LOCALE), $primary, 60 * 24 * 365 * 1);
        \Cookie::queue($this->cookieName(self::COOKIE_DISP_LOCALES), $display, 60 * 24 * 365 * 1);

        $this->setConnectionName($connection);

        if (\App::runningUnitTests()) {
            return \Redirect::to('/');
        }
        return !is_null(\Request::header('referer')) ? \Redirect::back() : \Redirect::to('/');
    }

    public function getUsageInfo()
    {
        $group = \Request::get('group');
        $reset = \Request::get('reset-usage-info');
        $show = \Request::get('show-usage-info');

        // need to store this so that it can be displayed again
        \Cookie::queue($this->cookieName(self::COOKIE_SHOW_USAGE), $show, 60 * 24 * 365 * 1);

        if ($reset) {
            // TODO: add show usage info to view variables so that a class can be added to keys that have no usage info
            // need to clear the usage information
            $this->manager->clearUsageCache(true, $group);
        }

        if (\App::runningUnitTests()) {
            return \Redirect::to('/');
        }
        return !is_null(\Request::header('referer')) ? \Redirect::back() : \Redirect::to('/');
    }

    public function getTransFilters()
    {
        $filter = null;
        $regex = null;

        if (\Request::has('filter')) {
            $filter = \Request::get("filter");
            $this->transFilters['filter'] = $filter;
        }

        $regex = \Request::get("regex", null);
        if ($regex !== null) {
            $this->transFilters['regex'] = $regex;
        }

        \Cookie::queue($this->cookieName(self::COOKIE_TRANS_FILTERS), $this->transFilters, 60 * 24 * 365 * 1);

        if (\Request::wantsJson()) {
            return \Response::json(array(
                'status'       => 'ok',
                'transFilters' => $this->transFilters,
            ));
        }

        return !is_null(\Request::header('referer')) ? \Redirect::back() : \Redirect::to('/');
    }

    public function getZippedTranslations($group = null)
    {
        // disable gzip compression of this page, this causes wrapping of the zip file in gzip format
        // does not work the zip is still gzip compressed
        if (ini_get('zlib.output_compression')) {
            ini_set('zlib.output_compression', 'Off');
            //\Log::info("after turning off zlib.compression current setting " . ini_get('zlib.output_compression'));
        }

        $file = $this->manager->zipTranslations($group);
        if ($group && $group !== '*') {
            $zip_name = "Translations_${group}_"; // Zip name
        } else {
            $zip_name = "Translations_"; // Zip name
        }

        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: attachment; filename="' . $zip_name . date('Ymd-His') . '.zip"');
        header('Content-Transfer-Encoding: binary');

        ob_clean();
        flush();
        readfile($file);
        unlink($file);
        //\Log::info("sending file, zlib.compression current setting " . ini_get('zlib.output_compression'));
    }

    public function postYandexKey()
    {
        return \Response::json(array(
            'status'     => 'ok',
            'yandex_key' => $this->manager->config('yandex_translator_key', null),
        ));
    }

    public function postUserLocales()
    {
        $user_id = \Request::get("pk");
        $values = \Request::get("value") ?: [];
        $userLocale = new UserLocales();

        $connection_name = $this->getConnectionName();
        /* @var $userLocales UserLocales */
        $userLocales = $userLocale->on($connection_name)->where('user_id', $user_id)->first();
        if (!$userLocales) {
            $userLocales = $userLocale;
            $userLocales->setConnection($connection_name);
            $userLocales->user_id = $user_id;
        }

        $userLocales->setConnection($connection_name);
        $userLocales->locales = implode(",", $values);
        $userLocales->save();
        $errors = "";
        return \Response::json(array('status' => $errors ? 'errors' : 'ok', 'errors' => $errors));
    }
}
