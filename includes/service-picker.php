<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Shared Category -> Subcategory -> Service picker.
 *
 * One implementation of the three-level service menu, used by:
 *   - modules/appointments.php   (appointment booking)
 *   - modules/walkins.php         (walk-in registration)
 *   - book.php                    (public booking request)
 *
 * Receptionists use this all day, so the picker is built for speed:
 *   - the hierarchy is pre-rendered as nested <optgroup> elements
 *   - an optional category quick-filter narrows the menu in one click
 *   - an optional search box filters by service / subcategory name
 *   - every option carries name, price and duration in data attributes
 *     so the caller can build its summary without another request
 */

/**
 * Services for the picker, already grouped by category then subcategory.
 *
 * @return array<int,array<string,mixed>>
 */
function service_picker_tree(bool $onlyActive = true): array
{
    static $cache = [];

    $key = $onlyActive ? 'active' : 'all';

    if (!isset($cache[$key])) {
        $cache[$key] = group_services_by_hierarchy(services_for_picker($onlyActive));
    }

    return $cache[$key];
}

/**
 * "Blow Dry — 500 ETB · 30 min", the label used everywhere a service is listed.
 */
function service_picker_label(array $service, bool $showPrice = true, bool $showDuration = true): string
{
    $parts = [(string)$service['name']];

    if ($showPrice) {
        $parts[] = money($service['price']) . ' ' . currency();
    }
    if ($showDuration) {
        $parts[] = minutes_to_duration((int)$service['duration_minutes']);
    }

    return implode(' — ', $parts);
}

/**
 * Render the picker row: category filter, search box and the grouped select.
 *
 * @param array<string,mixed> $options
 *   id            string  DOM id of the <select>            (required)
 *   tree          array   pre-fetched tree                  (defaults to service_picker_tree())
 *   placeholder   string  first option text
 *   showPrice     bool    append the price to each option
 *   showDuration  bool    append the duration to each option
 *   selectName    string  name="" of the select (omit for JS-driven forms)
 *   class         string  extra CSS classes
 */
function render_service_picker(array $options = []): void
{
    $id           = (string)($options['id'] ?? 'serviceSelect');
    $tree         = $options['tree'] ?? service_picker_tree(true);
    $placeholder  = (string)($options['placeholder'] ?? '— Choose a service —');
    $showPrice    = (bool)($options['showPrice'] ?? true);
    $showDuration = (bool)($options['showDuration'] ?? true);
    $selectName   = (string)($options['selectName'] ?? '');
    $class        = trim('form-select ' . (string)($options['class'] ?? ''));

    /* Category options come from the tree so the filter never shows a
       category that has no bookable service. */
    $categories = [];
    foreach ($tree as $node) {
        $categories[(int)$node['category']['id']] = (string)$node['category']['name'];
    }
    ?>

    <div class="service-picker" data-service-picker>
        <div class="row g-2">
            <div class="col-sm-5 col-lg-4">
                <label class="form-label small mb-1" for="<?php echo e($id); ?>CatFilter">Category</label>
                <select class="form-select form-select-sm" id="<?php echo e($id); ?>CatFilter" data-picker-category>
                    <option value="">All categories</option>
                    <?php foreach ($categories as $catId => $catName): ?>
                        <option value="<?php echo (int)$catId; ?>"><?php echo e($catName); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-7 col-lg-8">
                <label class="form-label small mb-1" for="<?php echo e($id); ?>Search">Search</label>
                <input type="search" class="form-control form-control-sm" id="<?php echo e($id); ?>Search"
                       placeholder="Type a service name…" data-picker-search autocomplete="off">
            </div>
        </div>

        <!--
          A native <select> may only nest ONE level: the HTML spec allows
          an optgroup to contain options only, and browsers silently flatten
          nested optgroups, which makes the two levels indistinguishable.
          So each CATEGORY is one optgroup and each SUBCATEGORY is a
          non-selectable separator option inside it.
        -->
        <select class="<?php echo e($class); ?> mt-2" id="<?php echo e($id); ?>" data-picker-select<?php echo $selectName !== '' ? ' name="' . e($selectName) . '"' : ''; ?>>
            <option value=""><?php echo e($placeholder); ?></option>
            <?php foreach ($tree as $node):
                $catName = (string)$node['category']['name'];
                $catKey  = (int)$node['category']['id']; ?>

                <optgroup label="<?php echo e($catName); ?>" data-category="<?php echo $catKey; ?>" data-role="category">
                    <?php foreach ($node['subcategories'] as $subNode):
                        $subName = (string)$subNode['subcategory']['name'];
                        $subKey  = (int)$subNode['subcategory']['id']; ?>
                        <option value="" disabled data-role="subhead"
                                data-category="<?php echo $catKey; ?>"
                                data-subcategory="<?php echo $subKey; ?>">— <?php echo e($subName); ?> —</option>
                        <?php foreach ($subNode['services'] as $service): ?>
                            <option value="<?php echo (int)$service['id']; ?>"
                                    data-role="service"
                                    data-category="<?php echo (int)$service['category_id']; ?>"
                                    data-subcategory="<?php echo (int)$service['subcategory_id']; ?>"
                                    data-path="<?php echo e(service_hierarchy_path($service)); ?>"
                                    data-name="<?php echo e($service['name']); ?>"
                                    data-price="<?php echo e($service['price']); ?>"
                                    data-dur="<?php echo (int)$service['duration_minutes']; ?>">
                                <?php echo e(service_picker_label($service, $showPrice, $showDuration)); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endforeach; ?>

                    <?php if (!empty($node['direct'])): ?>
                        <option value="" disabled data-role="subhead"
                                data-category="<?php echo $catKey; ?>"
                                data-subcategory="0">— <?php echo SERVICE_NO_SUBCATEGORY; ?> —</option>
                        <?php foreach ($node['direct'] as $service): ?>
                            <option value="<?php echo (int)$service['id']; ?>"
                                    data-role="service"
                                    data-category="<?php echo (int)$service['category_id']; ?>"
                                    data-subcategory="0"
                                    data-path="<?php echo e(service_hierarchy_path($service)); ?>"
                                    data-name="<?php echo e($service['name']); ?>"
                                    data-price="<?php echo e($service['price']); ?>"
                                    data-dur="<?php echo (int)$service['duration_minutes']; ?>">
                                <?php echo e(service_picker_label($service, $showPrice, $showDuration)); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>

                </optgroup>
            <?php endforeach; ?>
        </select>

        <div class="form-text" data-picker-status></div>
    </div>

    <?php
    service_picker_script();
}

/**
 * Emit the picker behaviour exactly once per page.
 */
function service_picker_script(): void
{
    static $done = false;

    if ($done) {
        return;
    }
    $done = true;
    ?>
    <script>
    /* Category quick-filter + live search over the grouped service menu.
       Options are physically detached when filtered out: `option.hidden`
       is not honoured consistently across browsers, detaching always is.
       Subcategory headings (data-role="subhead") are non-selectable separators:
       they are shown only while at least one real service sits under them,
       and they never count towards the "N service(s)" tally. */
    (function () {
        function init(root) {
            var select = root.querySelector('[data-picker-select]');
            if (!select || select.dataset.pickerReady) return;
            select.dataset.pickerReady = '1';

            var catSel = root.querySelector('[data-picker-category]');
            var search = root.querySelector('[data-picker-search]');
            var status = root.querySelector('[data-picker-status]');

            /* One group per category; separators live inside their group.
               The full original option list is captured up front because
               filtering detaches options, after which querySelectorAll
               could no longer see them. */
            var groups = Array.prototype.map.call(select.querySelectorAll('optgroup'), function (group) {
                return {
                    el: group,
                    category: group.getAttribute('data-category') || '',
                    all: Array.prototype.slice.call(group.querySelectorAll('option'))
                };
            });

            var placeholder = select.options[0];

            function isService(opt) {
                return opt.getAttribute('data-role') === 'service';
            }

            function subKey(opt) {
                return opt.getAttribute('data-subcategory') || '0';
            }

            /* Rebuild a group's children in their original order so the
               menu never reorders itself as the user types. */
            function repaint(group, showFn) {
                group.all.forEach(function (opt) {
                    if (opt.parentNode === group.el) { opt.remove(); }
                });
                group.all.forEach(function (opt) {
                    if (showFn(opt)) { group.el.appendChild(opt); }
                });
            }

            function apply() {
                var cat = catSel ? catSel.value : '';
                var q   = search ? search.value.trim().toLowerCase() : '';
                var visible = 0;

                groups.forEach(function (group) {
                    var catMatch = !cat || group.category === cat;
                    var subCounts = {};
                    var serviceVisible = {};
                    var anyInGroup = false;

                    /* Pass 1 — real services decide what is visible. */
                    group.all.forEach(function (opt) {
                        if (!isService(opt)) { return; }

                        var text = (opt.textContent + ' ' + (opt.getAttribute('data-path') || '')).toLowerCase();
                        var show = catMatch && (!q || text.indexOf(q) !== -1);

                        serviceVisible[opt.value] = show;
                        if (show) {
                            anyInGroup = true;
                            visible++;
                            var sub = subKey(opt);
                            subCounts[sub] = (subCounts[sub] || 0) + 1;
                        }
                    });

                    repaint(group, function (opt) {
                        if (isService(opt)) { return !!serviceVisible[opt.value]; }
                        /* A heading is only useful while its services survive. */
                        return catMatch && (subCounts[subKey(opt)] || 0) > 0;
                    });

                    /* A group with nothing left in it renders as a stray empty
                       heading, so detach the whole optgroup. */
                    if (anyInGroup && !group.el.parentNode) { select.appendChild(group.el); }
                    if (!anyInGroup && group.el.parentNode) { group.el.remove(); }
                });

                if (placeholder.parentNode) { select.insertBefore(placeholder, select.firstChild); }
                if (status) {
                    status.textContent = visible === 0
                        ? 'No matching service. Adjust the category or clear the search.'
                        : visible + ' service(s) available.';
                }

                /* Never leave a detached option selected. */
                if (!select.value) { select.selectedIndex = select.options.length ? 0 : -1; }
            }

            if (catSel) catSel.addEventListener('change', apply);
            if (search) search.addEventListener('input', apply);
            apply();
        }

        function boot() {
            document.querySelectorAll('[data-service-picker]').forEach(init);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
    })();
    </script>
    <?php
}
