<?php
use dokuwiki\ChangeLog\PageChangeLog;
require_once(__DIR__ . '/helper.php');

class syntax_plugin_reviewflow extends DokuWiki_Syntax_Plugin {
    function getType() { return 'substition'; }
    function getPType() { return 'block'; }
    function getSort() { return 158; }

    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('~~REVIEWFLOW\\|.*?~~', $mode, 'plugin_reviewflow');
        $this->Lexer->addSpecialPattern('~~REVIEWFLOWPAGES(?:\\|.*?)?~~', $mode, 'plugin_reviewflow');
    }

    function handle($match, $state, $pos, Doku_Handler $handler) {
        if (strpos($match, 'REVIEWFLOWPAGES') === 2) {
            $parts = explode('|', trim($match, '~'));
            $ns = $parts[1] ?? '';
            return ['pages', trim($ns)];
        }

        $data = trim(substr($match, 13, -2));
        $lines = explode("\n", str_replace(",", "\n", $data));
        $pairs = [];
        $render = null;

        foreach ($lines as $line) {
            [$k, $v] = array_map('trim', explode('=', $line, 2) + ["", ""]);
            if (strtolower($k) === 'render') {
                $render = strtolower($v);
            } elseif ($k !== '') {
                $pairs[] = [$k, $v];
            }
        }

        return ['flow', ['pairs' => $pairs, 'render' => $render]];
    }

    function render($mode, Doku_Renderer $renderer, $data) {
        global $ID;
        if ($mode !== 'xhtml') return false;

        global $INFO;
        $viewed_rev = $INFO['reviewflow_rendering_validated'] ?? pageinfo()['lastmod'];

        if ($data[0] === 'pages') {
            $ns = $data[1];
            $renderer->doc .= $this->renderPageList($ns);
            return true;
        }

        list($_, $payload) = $data;
        $pairs = $payload['pairs'];
        $version = null;
        foreach ($pairs as [$k, $v]) {
            if (strtolower($k) === 'version') 
                { $version = $v; }
            else              
                { $expected_roles[$k] = ltrim($v, '@'); }
        }


        $render_mode = $payload['render'] ?: $this->getConf('default_render');
        $meta = p_get_metadata($ID, 'plugin reviewflow') ?? [];

        // Check _validation_history consistency using fp_hash and prev_hash chain
        if (isset($meta['_validation_history'])) {
            $chain_ok = true;
            $prev_hash = '';
            $chain = $meta['_validation_chain'] ?? [];
            foreach ($meta['_validation_history'] as $i => $entry) {
                try {
                    $computed = compute_entry_hash($entry, $prev_hash);
                } catch (Throwable $e) {
                    msg("⚠ ReviewFlow: failed to compute hash from validation history: " . $e->getMessage(), -1);
                    continue;
                }
                //msg('DEBUG: Syntax-side hash input: ' . json_encode($entry), 0);
                //msg('DEBUG: Syntax-side computed hash: ' . $computed, 0);

                if (!isset($chain[$i]) || $chain[$i] !== $computed) {
                    $chain_ok = false;
                    $expected = $chain[$i] ?? 'missing';
                    msg('⚠ ReviewFlow: validation history appears tampered or inconsistent. Last computed hash: ' . hsc($computed) . ' | Expected: ' . hsc($expected), -1);
                    break;
                }
                $prev_hash = $computed;
            }
        }

        $previous_validated_rev = null;
        $previous_validated_rev_link = null;
        $current_version_is_valid = false;
        $current_version_is_unset = false;
        // Check _version_history consistency (cross-check with _validation_history)
        if (isset($meta['_version_history']) && isset($meta['_validation_history'])) {
            $valids = [];
            // build a map of revision per version from version history
            $version_rev = [];
            foreach ($meta['_version_history'] as $vh) {
                if (isset($vh['version'], $vh['rev'])) {
                    $version_rev[$vh['version']] = $vh['rev'];
                }
            }
            // now add version and current rev ($viewed_rev) if version is not in the $version_rev map
            if ($version !== null && !isset($version_rev[$version])) {
                $version_rev[$version] = $viewed_rev;
            }
            

            foreach ($meta['_validation_history'] as $v) {
                if (!isset($v['timestamp'], $v['role'], $v['user'], $v['version'])) continue;
                $ver = $v['version'];
                if (!isset($version_rev[$ver]) || $version_rev[$ver] !== $v['rev']) {
                    continue; // skip entries with a rev unknown in version history or with a rev mismatch
                }
                if (!isset($valids[$ver])) {
                    $valids[$ver] = [
                        'roles' => [$v['role'] => $v['user']],
                        'rev' => $v['rev'],
                        'expected' => $v['expected'] ?? []
                    ];
                } else {
                    $valids[$ver]['roles'][$v['role']] = $v['user'];
                }
            }
            foreach ($meta['_version_history'] as $entry) {
                if (!isset($entry['version'], $entry['rev'], $entry['expected'], $entry['confirmed_by'])) {
                    msg('⚠ ReviewFlow: malformed entry in version history.', -1);
                    continue;
                }
                $ver = $entry['version'];
                if (!isset($valids[$ver])) {
                    msg("⚠ ReviewFlow: version $ver in _version_history not found in _validation_history.", -1);
                    continue;
                }
                $val = $valids[$ver];
                //msg("DEBUG: Comparing version entry to validation-derived entry for $ver\n→ From version_history: " . json_encode($entry) . "\n→ From validation_history: " . json_encode($val), 0);
                if ($val['rev'] !== $entry['rev']) {
                    msg("⚠ ReviewFlow: revision mismatch for version $ver.", -1);
                }
                $entry['confirmed_by'] = array_map('strval', $entry['confirmed_by']);
                foreach ($entry['expected'] as $role => $user) {
                    if (in_array($role, ['version', 'render'], true)) continue;
                    if (isset($entry['confirmed_by'][$role]) && ltrim($user, '@') === $entry['confirmed_by'][$role]) continue;
                    msg("⚠ ReviewFlow: role mismatch for $role in version $ver.", -1);
                }
                foreach ($entry['confirmed_by'] as $role => $user) {
                    if (in_array($role, ['version', 'render'], true)) continue;
                    if (isset($entry['expected'][$role]) && ltrim($entry['expected'][$role], '@') === $user) continue;
                    msg("⚠ ReviewFlow: unexpected confirmation role $role in version $ver.", -1);
                }
                if ($entry['expected'] !== $val['expected']) {
                    msg("⚠ ReviewFlow: expected reviewers mismatch for version $ver.", -1);
                }
                //msg("DEBUG: Checking if version entry {$entry['version']} matches requested version $version and revision {$entry['rev']} == {$val['rev']}", 0);
                if ($val['rev'] === $entry['rev']) {
                    if ($version !== null && $version === $entry['version']) {
                        if ($viewed_rev == $entry['rev']) { 
                            $current_version_is_valid = true;
                        } else {
                            $previous_validated_rev = $entry['rev'];
                            $previous_validated_rev_link = wl($ID, 'rev=' . $previous_validated_rev);
                            $previous_validated_version = $entry['version'];
                            $current_version_is_unset = true;
                            //msg("DEBUG: Current page revision " . $viewed_rev . " does not match validated revision " . $entry['rev'], 0);
                        }
                        
                    } else {
                        $previous_validated_rev = $entry['rev'];
                        $previous_validated_rev_link = wl($ID, 'rev=' . $previous_validated_rev);
                        $previous_validated_version = $entry['version'];
                    }
                }
            }
        }

        // Determine which roles are confirmed and which are missing
        if (isset($valids) || $version!==null) {
            $confirmed_roles = $valids[$version]['roles'] ?? [];
        } else {
            $confirmed_roles = [];
        }
        $missing_roles = [];
        foreach ($expected_roles as $role => $user) {
            if (($confirmed_roles[$role] ?? null) !== $user) {
                $missing_roles[$role] = $user;
            }
        }

        $current_user = $_SERVER['REMOTE_USER'] ?? null;

        // Check if current user is listed in any reviewflow role
        $user_in_roles = in_array($current_user, $expected_roles, true);
        $renderer->info['cache'] = false; // Disable cache entirely for accurate per-user rendering
        $renderer->info['cachedepends']['files'][] = metaFN($ID, '.meta');
        $renderer->info['cachedepends']['user'] = $INFO['client'];

        // Prepare display names
        require_once(DOKU_INC . 'inc/auth.php');
        $fmt_user = function($u){
            // userlink returns HTML, so strip tags and escape
            $link = userlink($u);
            if($link){
                return hsc(strip_tags($link));
            }
            return hsc($u);
        };

        // Banner
        $color = $current_version_is_valid ? 'green' : 'red';
        $banner = '<div class="reviewflow-box reviewflow-banner-' . $color . '">';
        if ($current_version_is_unset) {
            $version_msg = 'Current version is not properly set, change it in review flow. ';
        } else {
            $version_msg = $version ? 'Current version: ' . hsc($version) : 'Current version: —';
        }
        if ($previous_validated_rev_link) {
            $text_link = $previous_validated_rev < $viewed_rev ? 'previous valid version' : 'current valid version';
            $version_msg .= ' (<a href="' . $previous_validated_rev_link . '">' . $text_link . '</a>)';
        } else {
            $version_msg .= ' (no previous valid version)';
        }
        $banner .= $version_msg . '<br>';
        $banner .= 'Review status: ' . ($current_version_is_valid ? 'complete.' : 'incomplete.') . '<br>';
        if (!$current_version_is_valid) {
            $lst = [];
            foreach ($missing_roles as $role => $user) {
                $lst[] = hsc($role) . ' (' . $fmt_user(ltrim($user,"@")) . ')';
            }
            $banner .= 'Missing review: ' . implode(', ', $lst);
        }
        $banner .= '</div>';

        $renderer->doc = $banner . $renderer->doc;

        // Use auth_quickaclcheck to check user permission
        $perm = auth_quickaclcheck($ID);

        if ($render_mode === 'table') {
            $renderer->doc .= '<table class="reviewflow-table reviewflow-confirm-table">';
            $renderer->doc .= "<tr><th>Version</th><td>" . hsc($version ?? '—') . "</td></tr>";
            foreach ($pairs as [$key, $value]) {
                if (strtolower($key) === 'version') continue;
                $confirmed = $confirmed_roles[$key] ?? null;
                $label = ucwords(str_replace('_', ' ', hsc($key)));
                $btn = '';
                // offer confirm only if expected username matches current user exactly
                $expectedUser = ltrim($value,'@');
                if (!$confirmed && $current_user && $expectedUser === $current_user) {
                    $btn = '<form method="post" action="' . wl($ID) . '" accept-charset="utf-8" style="display:inline">
    <input type="hidden" name="sectok" value="' . getSecurityToken() . '">
    <input type="hidden" name="id" value="' . hsc($ID) . '">
    <input type="hidden" name="reviewflow_stage" value="' . hsc($key) . '">
    <input type="submit" value="✔ Confirm">
</form>';
                }
                $who = $confirmed ? $fmt_user($confirmed) : $fmt_user(ltrim($value, '@'));
                $renderer->doc .= "<tr><th>$label</th><td>$who $btn</td></tr>";
            }
            $renderer->doc .= '</table>';
        } elseif ($render_mode === 'list') {
            $renderer->doc .= '<ul>';
            foreach ($pairs as [$key, $value]) {
                if (strtolower($key) === 'version') continue;
                $confirmed = $confirmed_roles[$key] ?? null;
                $label = ucwords(str_replace('_', ' ', hsc($key)));
                $who = $confirmed ? $fmt_user($confirmed) : $fmt_user(ltrim($value, '@'));
                $renderer->doc .= "<li><strong>$label:</strong> $who</li>";
            }
            $renderer->doc .= '</ul>';
        }

        return true;
    }

    private function renderPageList($ns) {
        $result = "<table class='reviewflow-table'><tr><th>Page</th><th>Missing</th></tr>";
        $dir = dirname(__FILE__, 4) . '/data/pages/';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'txt') continue;
            $id = str_replace(['/', '.txt'], [':', ''], substr($file->getPathname(), strlen($dir)));
            if ($ns && strpos($id, $ns . ':') !== 0) continue;

            $meta = p_get_metadata($id, 'plugin reviewflow') ?? [];
            $missing = [];
            foreach ($meta as $role => $data) {
                if (!isset($data['user'])) {
                    $expected = $data['expected'] ?? '';
                    if ($expected) {
                        $missing[] = "$role (@$expected)";
                    } else {
                        $missing[] = $role;
                    }
                }
            }
            if ($missing) {
                $result .= "<tr><td><a href='" . wl($id) . "'>" . hsc($id) . "</a></td><td>" . hsc(implode(', ', $missing)) . "</td></tr>";
            }
        }
        $result .= "</table>";
        return $result;
    }

}