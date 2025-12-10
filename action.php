<?php
require_once(__DIR__ . '/helper.php');

class action_plugin_reviewflow extends DokuWiki_Action_Plugin {
    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_confirm');
        $controller->register_hook('TPL_CONTENT_DISPLAY', 'BEFORE', $this, 'handle_tpl_override');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_early_redirect');
        $controller->register_hook('FORM_REVISIONS_OUTPUT', 'BEFORE', $this, 'handle_revision_highlight');
    }

    public function handle_confirm(Doku_Event $event) {
        global $ID;

        // we check if the page contains a reviewflow block
        $raw = io_readFile(wikiFN($ID));
        if (!preg_match('/~~REVIEWFLOW\|(.*?)~~/s', $raw, $match)) {
            // if not we just bail out
            return;
        }
        $reviewflow_block = $match[1];

        // Load existing metadata
        $meta = p_get_metadata($ID, 'plugin reviewflow') ?? [];


        $parsedFlow = [];
        $version = '';
        foreach (preg_split('/\s+/', trim($reviewflow_block)) as $pair) {
            if (strpos($pair, '=') === false) continue;
            [$key, $val] = explode('=', $pair, 2);
            $parsedFlow[trim($key)] = trim($val);
            if (strtolower(trim($key)) === 'version') {
                $version = trim($val);
            }
        }

        $previous_involved_users = $meta['currently_involved_users'] ?? [];
        $meta['currently_involved_users'] = array_values(array_map(
            fn($v) => ltrim($v, '@'),
            array_filter($parsedFlow, fn($k) => !in_array($k, ['version', 'render']), ARRAY_FILTER_USE_KEY)
        ));

        // check if this is a validation submission or just a normal page save
        if ( ($_SERVER['REQUEST_METHOD'] !== 'POST') || (!isset($_POST['reviewflow_stage'])) ) {
            // just a normal page save we update involved users if needed and bails out
            if ($meta['currently_involved_users'] !== $previous_involved_users) {
                // update involved users list
                p_set_metadata($ID, ['plugin' => ['reviewflow' => [
                    '_validation_history' => $meta['_validation_history'],
                    '_validation_chain' => $meta['_validation_chain'],
                    '_version_history' => $meta['_version_history'],
                    'validated_rev' => $meta['validated_rev'],
                    'currently_involved_users' => $meta['currently_involved_users']
                ]]]);
            }
            return;
        }


        $stage = $_POST['reviewflow_stage'];
        global $USERINFO;
        $user = $_SERVER['REMOTE_USER'] ?? $USERINFO['name'] ?? null;
        if (!$user) {
            msg("Unable to determine current user for validation.", -1);
            return;
        }
        if (!checkSecurityToken()) return;



        if (empty($parsedFlow) || isset($parsedFlow['']) || $version === '') {
            msg(implode("<BR/>\n", [
                "ReviewFlow: unable to parse the current review flow – validation aborted.",
                "→ Raw block: " . htmlspecialchars($reviewflow_block),
                "→ Parsed flow: " . htmlspecialchars(json_encode($parsedFlow)),
                "→ Extracted version: " . htmlspecialchars($version)
            ]), -1);
            return;
        }



        // Record validation history
        $external_ts = null;
        $ch = curl_init('https://www.google.com');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        if ($response = curl_exec($ch)) {
            if (preg_match('/^Date:\s+(.*)$/mi', $response, $matches)) {
                $external_ts = strtotime(trim($matches[1]));
            }
        }
        curl_close($ch);

        // Optionally collect fingerprint from client
        $fp = $_POST['reviewflow_fp'] ?? '';
        $fp_hash = hash('sha256', $fp);

        // Reject validation if fingerprint is missing
        if ($fp === '') {
            msg("Browser fingerprint is required for validation: aborted.", -1);
            return;
        }

        // Reject validation if same fingerprint used by a different user
        foreach ($meta['_validation_history'] ?? [] as $entry) {
            if (!isset($entry['fp_hash'])) continue;
            if ($entry['fp_hash'] === $fp_hash && $entry['user'] !== $user) {
                msg("It seems you are trying to counterfeit another user: validation aborted.", -1);
                return;
            }
        }

        $new_validation = [
            'rev' => @filemtime(wikiFN($ID)),
            'role' => $stage,
            'user' => $user,
            'timestamp' => time(),
            'external_ts' => $external_ts,
            'version' => $version,
            'expected' => $parsedFlow,
            'fp_hash' => $fp_hash,
        ];

        $hash_input = compute_entry_hash($new_validation);
        $meta['_validation_history'][] = $new_validation;

        // Compute hash chain for validation history
        if (!isset($meta['_validation_chain']) || !is_array($meta['_validation_chain'])) {
            $meta['_validation_chain'] = [];
        }
        $prev_hash = end($meta['_validation_chain']);
        if ($prev_hash === false) {
            $prev_hash = '';
        }
        $new_hash = compute_entry_hash($new_validation, $prev_hash);
        $meta['_validation_chain'][] = $new_hash;
        

        // Check if all validations are now complete for this revision
        $rev = @filemtime(wikiFN($ID));
        $validated = [];

        foreach (($meta['_validation_history'] ?? []) as $entry) {
            if ($entry['rev'] !== $rev) continue;
            $validated[$entry['role']] = $entry['user'];
        }

        $allConfirmed = true;
        foreach ($parsedFlow as $role => $expectedUser) {
            if ($role === 'version') continue;
            if ($role === 'render') continue;
            if (!isset($validated[$role]) || $validated[$role] !== ltrim($expectedUser, '@')) {
                $allConfirmed = false;
                break;
            }
        }


        if ($allConfirmed) {
            $meta['_version_history'][] = [
                'rev' => $rev,
                'timestamp' => time(),
                'confirmed_by' => $validated,
                'version' => $version,
                'expected' => $parsedFlow
            ];
            $meta['validated_rev'] = $rev;
        } else {
            //msg("DEBUG: Validation considered inconsistent", -1);
            //msg("→ Parsed flow: " . htmlspecialchars(json_encode($parsedFlow)), -1);
            //msg("→ Confirmed roles/users: " . htmlspecialchars(json_encode($validated)), -1);
            //msg("→ Validation history: " . htmlspecialchars(json_encode($meta['_validation_history'] ?? [])), -1);
            //msg("→ Validation chain: " . htmlspecialchars(json_encode($meta['_validation_chain'] ?? [])), -1);
        }

        p_set_metadata($ID, ['plugin' => ['reviewflow' => [
            '_validation_history' => $meta['_validation_history'],
            '_validation_chain' => $meta['_validation_chain'],
            '_version_history' => $meta['_version_history'],
            'validated_rev' => $meta['validated_rev'],
            'currently_involved_users' => $meta['currently_involved_users'],
        ]]]);
        

        // Purge page cache to reflect updated validation
        $cache = new \dokuwiki\Cache\CacheRenderer($ID, wikiFN($ID), 'xhtml');
        $cache->removeCache();

        // Also purge previous validated revision cache if it exists
        $oldMeta = p_get_metadata($ID, 'plugin reviewflow') ?? [];
        $lastValidRev = $oldMeta['validated'] ?? null;
        if ($lastValidRev) {
            $revPath = wikiFN($ID, $lastValidRev);
            $oldCache = new \dokuwiki\Cache\CacheRenderer($ID, $revPath, 'xhtml');
            $oldCache->removeCache();
        }

        // Redirect back to page
        send_redirect(wl($ID, '', true, '&'));

        return;
    }
    /**
     * Render a yellow banner for reviewflow notices.
     *
     * @param string $message The message to display (escaped).
     * @param string $html Optional HTML to output after the banner.
     */
    private function render_reviewflow_banner($message, $html = '') {
        echo '<div class="reviewflow-box reviewflow-banner-yellow">';
        echo hsc($message);
        echo '</div>';
        if ($html !== '') {
            echo $html;
        }
    }

    public function handle_tpl_override(Doku_Event $event) {
        global $ID, $INFO;
        if (($INFO['reviewflow_rendering_validated'] ?? null) === '__reviewflow_block__') {
            $event->preventDefault();
            $event->stopPropagation();
            $this->render_reviewflow_banner('No validated version exists yet. You cannot view this page.');
            return;
        }
        if (empty($INFO['reviewflow_rendering_validated'])) return;

        // Prevent standard page content rendering
        $event->preventDefault();
        $event->stopPropagation();

        // Render validated revision instead
        $rev = $INFO['reviewflow_rendering_validated'];
        $text = rawWiki($ID, $rev);
        if ($text === '') return;

        $instructions = p_get_instructions($text);
        $info = [];
        $html = p_render('xhtml', $instructions, $info);

        $this->render_reviewflow_banner(
            '',
            $html
        );
    }
    public function handle_early_redirect(Doku_Event $event) {
        global $ID;
        if ($event->data !== 'show') {
            return;
        }

        // Skip redirect when a specific revision is requested
        if (!empty($_REQUEST['rev'])) {
            return;
        }

        $meta = p_get_metadata($ID, 'plugin reviewflow') ?? [];
        $validated_rev = $meta['validated_rev'] ?? null;

        // Fallback compute involved users if metadata missing
        if (!isset($meta['currently_involved_users'])) {
            $raw = io_readFile(wikiFN($ID));
            if (preg_match('/~~REVIEWFLOW\|(.*?)~~/s', $raw, $match)) {
                $reviewflow_block = $match[1];
                $parsedFlow = [];
                foreach (preg_split('/\s+/', trim($reviewflow_block)) as $pair) {
                    if (strpos($pair, '=') === false) continue;
                    [$key, $val] = explode('=', $pair, 2);
                    $parsedFlow[trim($key)] = trim($val);
                }
                $meta['currently_involved_users'] = array_values(array_map(
                    fn($v) => ltrim($v, '@'),
                    array_filter($parsedFlow, fn($k) => !in_array($k, ['version','render']), ARRAY_FILTER_USE_KEY)
                ));
            } else {
                return;
            }
        }

        $involved = $meta['currently_involved_users'] ?? [];
        $current_user = $_SERVER['REMOTE_USER'] ?? null;

        if (!$current_user || in_array($current_user, $involved)) {
            return;
        }

        global $INFO;
        $current_rev = @filemtime(wikiFN($ID));
        if ($validated_rev && $validated_rev !== $current_rev) {
            $_REQUEST['rev'] = $validated_rev;
            $INFO['reviewflow_rendering_validated'] = $validated_rev;
        } elseif (!$validated_rev) {
            $INFO['reviewflow_rendering_validated'] = '__reviewflow_block__';
        }
    }
    public function handle_revision_highlight(Doku_Event $event) {
        global $INFO;
        $form = $event->data;
        if (!$form instanceof \dokuwiki\Form\Form) return;

        $meta = p_get_metadata($INFO['id'], 'plugin reviewflow') ?? [];
        $validated_rev = $meta['validated_rev'] ?? null;

        $version_map = [];
        foreach ($meta['_version_history'] ?? [] as $entry) {
            if (!isset($entry['rev'], $entry['version'])) continue;
            $version_map[$entry['rev']] = $entry['version'];
        }

        $elCount = $form->elementCount();
        $checkName = 'rev2[]';

        for ($i = 0; $i < $elCount; $i++) {
            $el = $form->getElementAt($i);

            if (!$el instanceof \dokuwiki\Form\CheckableElement && !$el instanceof \dokuwiki\Form\HTMLElement) {
                continue;
            }

            if ($el instanceof \dokuwiki\Form\CheckableElement && $el->attr('name') === $checkName) {
                $rev = (int)$el->attr('value');
                $currentCheckbox = $el;
            }

            if (!isset($rev)) continue;

            $version = $version_map[$rev] ?? null;

            if ($el instanceof \dokuwiki\Form\HTMLElement && !empty(trim($el->val()))) {
                $label = '';
                if ($version) {
                    $label .= ' <span class="reviewflow-version-label">v' . hsc($version) . '</span>';
                }
                if ($label) {
                    $val = $el->val();
                    $el->val("$val $label");
                }
            }
            if (isset($currentCheckbox)) {
                $pos = $form->getElementPosition($currentCheckbox);

                // Search backwards for the immediate TagOpenElement 'div' to add a class to it
                for ($offset = 1; $pos - $offset >= 0; $offset++) {
                    $candidate = $form->getElementAt($pos - $offset);

                    if (!$candidate instanceof \dokuwiki\Form\TagOpenElement) {
                        continue;
                    }

                    if ($candidate->val() === 'div') {
                        if ($version !== null) {
                            $candidate->addClass('reviewflow-has-version');
                        } else {
                            $candidate->addClass('reviewflow-no-version');
                        }
                        break;
                    }
                }

                unset($currentCheckbox);
            }
        }
    }
}?>