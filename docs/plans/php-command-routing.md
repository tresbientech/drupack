# Plan: PHP command routing without emulation

> Source PRD: `docs/prd/php-command-routing.md`. RFC: `docs/rfc/0005-php-command-routing.md`.

## Architectural decisions

- The Runtime's entry point owns a `php` word. It accepts `SCRIPT [ARGUMENTS]` and `-r CODE`, rewrites either to `php-cli` unchanged, and refuses anything else before PHP starts.
- The word runs only when the Runtime has no application directory. A Packaged site refuses it as an unknown command.
- The Engine launcher and both aliases reach the word. Nothing else decides the form.
- No Drupack PHP command writes or reads an INI file in a temporary directory.

---

## Phase 1: the php word replaces the frontend

User stories: 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 16, 17

### What to build

The Runtime's entry point gains the `php` word and its refusal. The Engine
launcher passes `php` through, and the Unix and Windows aliases call the word.
The emulating frontend, its unit file and its environment-contract entry leave
the repository. The Engine's conformance cases cover the two forms, each
refusal, the planted directory, Drush's child processes and the Packaged site's
refusal.

### Acceptance criteria

- [x] An engine case runs `drupack php SCRIPT a b` and the alias `php SCRIPT a b`, and checks arguments, working directory and exit status.
- [x] An engine case runs `drupack php -r 'echo 1;'` and reads `1`.
- [x] An engine case runs `-d x=1`, `-l`, `-v` and `-S` through the launcher and the alias, and checks exit 1, the option named, and no PHP output.
- [x] An engine case runs `-r CODE extra` and checks the refusal names `extra`.
- [x] An engine case plants a `drupack-php-*` directory with an `auto_prepend_file` setting in the temporary directory, and checks a script's output carries nothing from it.
- [x] The existing engine cases that run a Project folder's Drush pass.
- [x] A site case runs `php x.php` against Mercury Demo and checks `Unknown command: php`.
- [ ] A Windows engine case passes arguments with spaces, quotes and `%` through `php.cmd`, pending CI on the Windows job. The case passes on Linux through `bin/php`.
- [x] `git grep -n -e php.php -e settingsDirectory -e drupack-php-` finds only the RFC, the PRD and this plan. It also finds RFC 0003's problem statement, the planted-directory case, and `php/php` in Mercury's `composer.lock`.
- [x] `python3 -m unittest discover -s tests/conformance -p test_environment.py` passes.

---

## Phase 2: docs, ADR and backlog

User stories: 15, 18

### What to build

The CLI reference and the README list the two forms and the refusal. ADR 0022's
`drupack php` passage says the same. A backlog entry records dropping the
refusal once the pinned FrankenPHP runs PHP's own command line.

### Acceptance criteria

- [x] `git grep -n -e "-d SETTING" -e "with \`-v\`, \`-m\`, \`-d\`" docs README.md` finds nothing. It finds this criterion's own line.
- [x] `git grep -n "php-cli" docs/backlog.md` finds the entry naming PHP 8.6.
- [x] ADR 0022 names the two forms.
- [ ] `bash build/qa.sh` passes, and `.git/qa-green` records it.
