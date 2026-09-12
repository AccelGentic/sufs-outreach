<?php
require_once __DIR__ . '/../config.php';
session_name(SESSION_NAME);
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

$universities = get_universities();
$relationships = get_relationships();
$suggestEnabled = school_requests_enabled();
$errors = $_SESSION['form_errors'] ?? [];
$old = $_SESSION['form_old'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['form_old']);

// On a validation-error round trip, prefill the visible search box with
// the name of whatever was previously selected (the hidden field only
// ever stores an id, so the typeahead needs the matching name to show).
$oldUniversityName = '';
if (!empty($old['university_id'])) {
    $prevUniversity = get_university((int) $old['university_id']);
    if ($prevUniversity) {
        $oldUniversityName = $prevUniversity['name'];
    }
}

// Embedded once for the client-side fuzzy typeahead -- at a few thousand
// rows this is far cheaper than round-tripping to the server on every
// keystroke. JSON_HEX_* flags make it safe to sit inside an inline
// <script> tag even if a university name ever contained something like
// </script> or an ampersand.
$universitiesJson = json_encode(
    $universities,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Contact an Educational Institution</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php require __DIR__ . '/../includes/staging_banner.php'; ?>
<?php require __DIR__ . '/../includes/site_header.php'; ?>
<main class="card">
  <h1>Contact an Educational Institution</h1>
  <p>Fill out your information below. On the next step you'll be able to review and edit your message before anything is sent.</p>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <ul>
        <?php foreach ($errors as $err): ?>
          <li><?= h($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form action="generate.php" method="post" id="outreach-form" novalidate>
    <?= csrf_field() ?>

    <label for="first_name">First name *</label>
    <input type="text" id="first_name" name="first_name" required maxlength="100"
           value="<?= h($old['first_name'] ?? '') ?>">

    <label for="last_name">Last name *</label>
    <input type="text" id="last_name" name="last_name" required maxlength="100"
           value="<?= h($old['last_name'] ?? '') ?>">

    <label for="email">Email *</label>
    <input type="email" id="email" name="email" required maxlength="255"
           value="<?= h($old['email'] ?? '') ?>">

    <label for="university_search">Educational Institution *</label>
    <div class="typeahead" id="university_typeahead">
      <input type="text" id="university_search" autocomplete="off"
             placeholder="Start typing an educational institution name..."
             role="combobox" aria-expanded="false" aria-autocomplete="list"
             aria-controls="university_listbox"
             value="<?= h($oldUniversityName) ?>">
      <input type="hidden" id="university_id" name="university_id"
             value="<?= h($old['university_id'] ?? '') ?>">
      <ul id="university_listbox" class="typeahead-list" role="listbox" hidden></ul>
    </div>
    <p class="field-error" id="university_error" hidden>Please select an educational institution from the list.</p>
    <?php if ($suggestEnabled): ?>
      <p class="hint">
        Don't see your school?
        <a href="suggest_school.php" id="suggest_school_link">Tell us about it</a>.
      </p>
    <?php endif; ?>

    <label for="relationship_id">Your Relationship to This Educational Institution *</label>
    <select id="relationship_id" name="relationship_id" required>
      <option value="">-- Select your relationship --</option>
      <?php foreach ($relationships as $r): ?>
        <option value="<?= (int) $r['id'] ?>"
          <?= (isset($old['relationship_id']) && (int) $old['relationship_id'] === (int) $r['id']) ? 'selected' : '' ?>>
          <?= h($r['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label for="address">Address (optional)</label>
    <textarea id="address" name="address" rows="2" maxlength="500"><?= h($old['address'] ?? '') ?></textarea>

    <button type="submit">Continue</button>
  </form>
</main>

<script>
var UNIVERSITIES = <?= $universitiesJson ?>;
var SUGGEST_ENABLED = <?= $suggestEnabled ? 'true' : 'false' ?>;

(function () {
  var MAX_RESULTS = 20;

  var wrapper   = document.getElementById('university_typeahead');
  var search    = document.getElementById('university_search');
  var hiddenId  = document.getElementById('university_id');
  var listbox   = document.getElementById('university_listbox');
  var errorMsg  = document.getElementById('university_error');
  var form      = document.getElementById('outreach-form');

  var visibleItems = []; // [{id, name}] currently rendered, in order
  var highlighted = -1;

  var suggestLink = document.getElementById('suggest_school_link');

  // Carries whatever they typed over to the suggest form, so they don't
  // retype the school name they just failed to find.
  function suggestUrl(query) {
    var q = (query || '').trim();
    return 'suggest_school.php' + (q ? '?school=' + encodeURIComponent(q) : '');
  }

  // Subsequence match of `needle` inside `haystack` (both already
  // lowercased). Returns null if needle's characters don't all appear
  // in order, otherwise a score where higher = better: runs of
  // consecutive matched characters and matches at word boundaries both
  // score better than scattered single-character matches.
  function subsequenceScore(needle, haystack) {
    if (needle === '') return 0;
    var ni = 0, score = 0, run = 0, firstIndex = -1;
    for (var hi = 0; hi < haystack.length && ni < needle.length; hi++) {
      if (haystack[hi] === needle[ni]) {
        if (firstIndex === -1) firstIndex = hi;
        run++;
        score += run;
        if (hi === 0 || haystack[hi - 1] === ' ') score += 2;
        ni++;
      } else {
        run = 0;
      }
    }
    if (ni < needle.length) return null;
    return score - firstIndex * 0.1;
  }

  // Query is split on whitespace so "mich state" or "state mich" both
  // match "Michigan State University" -- every token has to match
  // *somewhere* in the name, in any order, and their scores add up.
  function matchScore(query, name) {
    var tokens = query.toLowerCase().trim().split(/\s+/).filter(Boolean);
    if (tokens.length === 0) return 0;
    var lowerName = name.toLowerCase();
    var total = 0;
    for (var i = 0; i < tokens.length; i++) {
      var s = subsequenceScore(tokens[i], lowerName);
      if (s === null) return null;
      total += s;
    }
    return total;
  }

  function closeList() {
    listbox.hidden = true;
    listbox.innerHTML = '';
    visibleItems = [];
    highlighted = -1;
    search.setAttribute('aria-expanded', 'false');
    search.removeAttribute('aria-activedescendant');
  }

  function clearSelection() {
    hiddenId.value = '';
  }

  function selectUniversity(item) {
    hiddenId.value = item.id;
    search.value = item.name;
    errorMsg.hidden = true;
    closeList();
  }

  function setHighlighted(index) {
    var options = listbox.querySelectorAll('li');
    options.forEach(function (el) { el.setAttribute('aria-selected', 'false'); });
    highlighted = index;
    if (index >= 0 && options[index]) {
      options[index].setAttribute('aria-selected', 'true');
      options[index].scrollIntoView({ block: 'nearest' });
      search.setAttribute('aria-activedescendant', options[index].id);
    } else {
      search.removeAttribute('aria-activedescendant');
    }
  }

  function renderResults(query) {
    listbox.innerHTML = '';

    if (query.trim() === '') {
      closeList();
      return;
    }

    var scored = [];
    for (var i = 0; i < UNIVERSITIES.length; i++) {
      var u = UNIVERSITIES[i];
      var s = matchScore(query, u.name);
      if (s !== null) scored.push({ item: u, score: s });
    }
    scored.sort(function (a, b) { return b.score - a.score; });

    var shown = scored.slice(0, MAX_RESULTS);
    visibleItems = shown.map(function (r) { return r.item; });

    if (visibleItems.length === 0) {
      var li = document.createElement('li');
      li.className = 'typeahead-empty';
      li.textContent = 'No matching educational institutions';
      listbox.appendChild(li);

      // This is the exact moment someone finds out their school isn't
      // listed, which is why the offer to add it belongs here rather
      // than in a banner above the form that everyone reads before they
      // have the problem.
      if (SUGGEST_ENABLED) {
        var suggest = document.createElement('li');
        suggest.className = 'typeahead-suggest';
        var link = document.createElement('a');
        link.href = suggestUrl(query);
        link.textContent = 'Tell us about your school \u2192';
        // mousedown, like the option rows above: the input blurs on
        // click and would close the list before the link registered.
        link.addEventListener('mousedown', function (e) {
          e.stopPropagation();
          window.location.href = link.href;
        });
        suggest.appendChild(link);
        listbox.appendChild(suggest);
      }

      listbox.hidden = false;
      search.setAttribute('aria-expanded', 'true');
      return;
    }

    visibleItems.forEach(function (item, idx) {
      var li = document.createElement('li');
      li.id = 'university_option_' + item.id;
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', 'false');
      li.textContent = item.name;
      // mousedown (not click) fires before the input blurs, so the
      // selection registers before closeList()/blur handling would
      // otherwise race it.
      li.addEventListener('mousedown', function (e) {
        e.preventDefault();
        selectUniversity(item);
      });
      listbox.appendChild(li);
    });

    if (scored.length > MAX_RESULTS) {
      var more = document.createElement('li');
      more.className = 'typeahead-empty';
      more.textContent = (scored.length - MAX_RESULTS) + ' more -- keep typing to narrow it down';
      listbox.appendChild(more);
    }

    listbox.hidden = false;
    search.setAttribute('aria-expanded', 'true');
    setHighlighted(0);
  }

  var debounceTimer = null;
  search.addEventListener('input', function () {
    clearSelection(); // typing invalidates any previous selection
    if (suggestLink) {
      suggestLink.href = suggestUrl(search.value);
    }
    clearTimeout(debounceTimer);
    var value = search.value;
    debounceTimer = setTimeout(function () { renderResults(value); }, 60);
  });

  search.addEventListener('keydown', function (e) {
    if (listbox.hidden) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setHighlighted(Math.min(highlighted + 1, visibleItems.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setHighlighted(Math.max(highlighted - 1, 0));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (highlighted >= 0 && visibleItems[highlighted]) {
        selectUniversity(visibleItems[highlighted]);
      }
    } else if (e.key === 'Escape') {
      closeList();
    }
  });

  document.addEventListener('click', function (e) {
    if (!wrapper.contains(e.target)) closeList();
  });

  form.addEventListener('submit', function (e) {
    if (!hiddenId.value) {
      e.preventDefault();
      errorMsg.hidden = false;
      search.focus();
    }
  });
})();
</script>
</body>
</html>
