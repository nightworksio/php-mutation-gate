(() => {
  'use strict';

  const data = JSON.parse(document.getElementById('data').textContent);
  const depth = document.getElementById('depth');
  const body = depth.querySelector('.depth-body');
  const modes = depth.querySelector('.modes');
  const list = document.getElementById('file-list');
  const pick = document.getElementById('file-pick');
  const pane = document.getElementById('pane');

  const LABELS = {
    escaped: 'Escaped',
    untested: 'Untested',
    'timed-out': 'Timed out',
    errored: 'Errored',
    killed: 'Killed',
    'left-out': 'Left out',
  };
  const FOLDED = ['killed', 'left-out'];

  let mode = 'lines';
  let current = -1;

  function element(tag, className, text) {
    const made = document.createElement(tag);

    if (className) {
      made.className = className;
    }

    if (text !== undefined) {
      made.textContent = text;
    }

    return made;
  }

  function prose(className, text) {
    const said = element('p', className);
    text.split('`').forEach((part, at) => {
      said.appendChild(at % 2 === 1 ? element('code', '', part) : document.createTextNode(part));
    });

    return said;
  }

  function tag(outcome) {
    return element('span', 'tag tag--' + outcome, LABELS[outcome]);
  }

  function diffLines(diff) {
    return diff.split('\n').filter((line) => line !== '' && !line.startsWith('@@')).map((line) => ({
      kind: line[0] === '+' ? 'added' : line[0] === '-' ? 'removed' : 'context',
      text: line,
    }));
  }

  function lineElement(line) {
    const kind = line.kind === 'context' ? '' : ' diff-line--' + line.kind;

    return element('code', 'diff-line' + kind, line.text);
  }

  function column(lines) {
    const side = element('div', 'diff-side');
    lines.forEach((line) => side.appendChild(line === null ? element('code', 'diff-line', ' ') : lineElement(line)));

    return side;
  }

  function split(lines) {
    const left = [];
    const right = [];
    let removed = [];
    let added = [];

    const flush = () => {
      const rows = Math.max(removed.length, added.length);

      for (let at = 0; at < rows; at++) {
        left.push(removed[at] || null);
        right.push(added[at] || null);
      }

      removed = [];
      added = [];
    };

    lines.forEach((line) => {
      if (line.kind === 'removed') {
        removed.push(line);
      } else if (line.kind === 'added') {
        added.push(line);
      } else {
        flush();
        left.push(line);
        right.push(line);
      }
    });
    flush();

    const shown = element('div', 'diff diff-split');
    shown.appendChild(column(left));
    shown.appendChild(column(right));

    return shown;
  }

  function diff(text) {
    if (text === undefined) {
      return element('p', 'aside', 'No diff was kept for it: a proof carried its kill.');
    }

    const lines = diffLines(text);

    if (mode === 'split') {
      return split(lines);
    }

    const shown = element('pre', 'diff');
    lines.filter((line) => mode === 'unified' || line.kind !== 'context')
      .forEach((line) => shown.appendChild(lineElement(line)));

    return shown;
  }

  function killers(mutant) {
    if (mutant.killedBy.length > 0) {
      return element('p', '', 'Killed by ' + mutant.killedBy.map((test) => data.tests[test]).join(', ') + '.');
    }

    if (mutant.outcome === 'killed') {
      return element('p', 'aside', 'No test is named as its killer.');
    }

    const covered = mutant.covered === 0
      ? 'No test covers it.'
      : mutant.covered + (mutant.covered === 1 ? ' test covers it.' : ' tests cover it.');

    return element('p', '', 'No test killed it. ' + covered);
  }

  function card(mutant) {
    const shown = element('article', 'mutant mutant--' + mutant.outcome);
    shown.id = 'm-' + mutant.id;

    const head = element('div', 'mutant-head');
    head.appendChild(tag(mutant.outcome));
    head.appendChild(element('strong', '', 'line ' + mutant.line));
    const mutator = element('span', 'mutator', mutant.mutator.slice(mutant.mutator.lastIndexOf('\\') + 1));
    mutator.title = mutant.mutator;
    head.appendChild(mutator);
    head.appendChild(element('span', 'judgement', mutant.judgement));

    if (mutant.changed) {
      head.appendChild(element('span', 'chip', 'changed line'));
    }

    shown.appendChild(head);
    shown.appendChild(diff(mutant.diff));
    shown.appendChild(killers(mutant));

    if (mutant.reason !== undefined) {
      shown.appendChild(prose('aside', mutant.reason));
    }

    if (mutant.outcome !== 'killed' && mutant.outcome !== 'left-out') {
      shown.appendChild(prose('', mutant.hint));
      shown.appendChild(element('code', 'reproduce', mutant.reproduce));
    }

    return shown;
  }

  function group(outcome, mutants) {
    const folded = element('details', 'group');
    folded.dataset.outcome = outcome;
    folded.appendChild(element('summary', '', LABELS[outcome] + ' (' + mutants.length + ')'));
    folded.addEventListener('toggle', () => {
      if (folded.open && folded.children.length === 1) {
        mutants.forEach((mutant) => folded.appendChild(card(mutant)));
      }
    });

    return folded;
  }

  function counts(file) {
    const line = element('p', 'aside');
    const said = Object.keys(LABELS)
      .filter((outcome) => file.counts[outcome] > 0)
      .map((outcome) => file.counts[outcome] + ' ' + LABELS[outcome].toLowerCase());
    line.textContent = said.length === 0 ? 'No mutants.' : said.join(', ') + '.';

    return line;
  }

  function render(at) {
    const file = data.files[at];
    pane.replaceChildren();

    const head = element('div', 'pane-head');
    head.appendChild(element('h3', '', file.path));
    pane.appendChild(head);
    pane.appendChild(counts(file));

    if (file.carried) {
      pane.appendChild(element('p', 'empty', 'This run did not judge this file: a proof or an earlier run carried its result, so only its counts are shown.'));

      return;
    }

    const open = file.mutants.filter((mutant) => !FOLDED.includes(mutant.outcome));

    if (open.length === 0) {
      pane.appendChild(element('p', 'empty', 'Every mutant of this file was killed or left out.'));
    }

    open.forEach((mutant) => pane.appendChild(card(mutant)));
    FOLDED.forEach((outcome) => {
      const mutants = file.mutants.filter((mutant) => mutant.outcome === outcome);

      if (mutants.length > 0) {
        pane.appendChild(group(outcome, mutants));
      }
    });
  }

  function show(at) {
    if (at < 0 || at >= data.files.length) {
      return;
    }

    current = at;
    render(at);
    pick.value = String(at);
    list.querySelectorAll('button').forEach((button) => {
      button.setAttribute('aria-current', button.dataset.at === String(at) ? 'true' : 'false');
    });
  }

  function reveal(id) {
    const at = data.files.findIndex((file) => file.mutants.some((mutant) => mutant.id === id));

    if (at < 0) {
      return;
    }

    if (at !== current) {
      show(at);
    }

    let target = document.getElementById('m-' + id);

    if (target === null) {
      const outcome = data.files[at].mutants.find((mutant) => mutant.id === id).outcome;
      const folded = pane.querySelector('details.group[data-outcome="' + outcome + '"]');

      if (folded !== null) {
        folded.open = true;
        folded.dispatchEvent(new Event('toggle'));
        target = document.getElementById('m-' + id);
      }
    }

    if (target !== null) {
      pane.querySelectorAll('.is-target').forEach((shown) => shown.classList.remove('is-target'));
      target.classList.add('is-target');
      target.scrollIntoView({ block: 'start' });
    }
  }

  function route() {
    const hash = decodeURIComponent(window.location.hash.slice(1));

    if (hash.startsWith('m-')) {
      reveal(hash.slice(2));
    } else if (hash.startsWith('f-')) {
      show(Number(hash.slice(2)));
      depth.scrollIntoView({ block: 'start' });
    }
  }

  body.hidden = false;

  if (data.files.length === 0) {
    body.replaceChildren(element('p', 'empty', 'No mutants in this run.'));

    return;
  }

  modes.hidden = false;
  modes.addEventListener('change', (event) => {
    mode = event.target.value;

    if (current >= 0) {
      render(current);
    }
  });

  data.files.forEach((file, at) => {
    const item = element('li');
    const button = element('button');
    button.type = 'button';
    button.dataset.at = String(at);
    const slash = file.path.lastIndexOf('/');
    const path = element('span', 'path');
    path.appendChild(element('span', 'path-dir', file.path.slice(0, slash + 1)));
    path.appendChild(element('span', 'path-base', file.path.slice(slash + 1)));
    button.appendChild(path);

    if (file.survivors > 0) {
      button.appendChild(element('span', 'tag tag--escaped', String(file.survivors)));
    }

    button.addEventListener('click', () => show(at));
    item.appendChild(button);
    list.appendChild(item);

    const option = element('option', '', file.path + (file.survivors > 0 ? ' (' + file.survivors + ' surviving)' : ''));
    option.value = String(at);
    pick.appendChild(option);
  });
  pick.addEventListener('change', () => show(Number(pick.value)));
  window.addEventListener('hashchange', route);

  show(0);
  route();
})();
