# Spryker Development – Claude Skill

An [Agent Skill](https://docs.claude.com/en/docs/claude-code/skills) for **Claude Code** (and other Claude clients with skill support) that turns Claude into a knowledgeable partner for **project development with Spryker** – backend (Zed), frontend (Yves) and Glue API.

The skill bundles verified knowledge on 30 topics, twelve ready-to-use code scaffolds built around one running example, and review, upgradability and release checklists. Claude loads it automatically as soon as a task involves Spryker, and then reads only the reference that matches the task.

> **Version:** Spryker **b2b-demo-shop 202610.0** (tag commit `d45396b8`, spryker/kernel 3.87.1, spryker/oms 11.55.0, PHP 8.4, Node 24, Symfony 6.4, Docker SDK 1.82.0).
> All statements were verified against the source code of this release, and all non-destructive commands were executed live in the container. Where the official docs deviate from the code, the code wins – such places are explicitly marked in the skill.

---

## Contents

- [What the skill does](#what-the-skill-does)
- [Repository structure](#repository-structure)
- [Installation](#installation)
- [Usage](#usage)
- [Topic overview](#topic-overview)
- [Templates](#templates)
- [Checklists](#checklists)
- [Quality standard and known gaps](#quality-standard-and-known-gaps)
- [Customizing and extending](#customizing-and-extending)

---

## What the skill does

**Core principles Claude follows with this skill**

1. **Change Pyz, not the core** – never edit `vendor/`; extend on the project level (`src/Pyz`).
2. **Extension strategy in order of preference** – configuration → plugin in the Pyz DependencyProvider → project module → module customization → module replacement.
3. **Respect the layers** – Yves/Glue → Client → Storage/Search/Zed gateway; Zed modules talk to each other only via Facades; data crosses module boundaries only as transfer objects.
4. **Logic in the Business layer** – controllers, plugins and Twig only delegate.
5. **Upgradability** – no private core APIs, no copied core methods, flat plugin registration, run the evaluator regularly.
6. **Invent nothing** – every statement carries its verification status (`live verified (202610.0)`, `code-verified, docs not cross-checked`, `Assumption`).

**Typical tasks**

- Create a new project module with Facade, Factory, DependencyProvider, Repository and EntityManager
- Transfers (`*.transfer.xml`) and Propel schema, migrations
- Publish & Synchronization to Redis/OpenSearch and read it via a Client
- Data Import, OMS processes, cart/price/checkout extensions
- Yves controllers, routes, widgets, Atomic Frontend components (Twig, SCSS, TypeScript), themes, frontend build
- Glue Storefront and Glue Backend API resources
- Back Office pages with tables, forms, navigation and ACL; CMS pages, blocks, slots
- Multi-store/locales, B2B permissions, security/session, console commands, cron jobs, queue workers
- Codeception tests, PHPStan, Code Sniffer, Architecture Sniffer, Upgradability Evaluator, Docker SDK workflow
- Make decisions: plugin vs. inheritance vs. configuration, Facade vs. Client, Glue Storefront vs. Backend, Repository vs. QueryContainer

---

## Repository structure

```
spryker-claude-skill/
├── README.md
└── spryker-development/           ← the actual skill (this folder gets installed)
    ├── SKILL.md                   ← entry point: principles, workflow, decision guides, table of contents
    ├── references/                ← 30 topic references (01 … 30)
    ├── templates/                 ← 12 verified scaffolds with the running example "ProductBadge"
    └── checklists/                ← review, upgradability and release/deployment checklists
```

`SKILL.md` is intentionally kept compact. Details live in `references/`, so Claude only loads into context what the current task needs (progressive disclosure).

---

## Installation

### Prerequisites

- [Claude Code](https://docs.claude.com/en/docs/claude-code) (CLI, desktop app, or IDE extension)
- Git
- To work with the skill: a Spryker project, ideally based on `spryker-shop/b2b-demo-shop` 202610.0 with the [Docker SDK](https://github.com/spryker/docker-sdk)

### Option A – globally for all projects (recommended)

The skill is then available in every project.

```bash
git clone git@github.com:mibexx/spryker-claude-skill.git
mkdir -p ~/.claude/skills
cp -R spryker-claude-skill/spryker-development ~/.claude/skills/
```

Alternatively via symlink, so that a `git pull` updates the skill directly:

```bash
git clone git@github.com:mibexx/spryker-claude-skill.git ~/src/spryker-claude-skill
mkdir -p ~/.claude/skills
ln -s ~/src/spryker-claude-skill/spryker-development ~/.claude/skills/spryker-development
```

### Option B – for a single project only (shareable with the team)

The skill lives in the project repository and is committed along with it, so everyone on the team uses it automatically.

```bash
cd /path/to/spryker-project
mkdir -p .claude/skills
git clone git@github.com:mibexx/spryker-claude-skill.git /tmp/spryker-claude-skill
cp -R /tmp/spryker-claude-skill/spryker-development .claude/skills/
```

Or as a Git submodule:

```bash
git submodule add git@github.com:mibexx/spryker-claude-skill.git .claude/vendor/spryker-claude-skill
ln -s ../vendor/spryker-claude-skill/spryker-development .claude/skills/spryker-development
```

### Option C – claude.ai / Claude Desktop

1. Pack the `spryker-development` folder as a ZIP:
   ```bash
   cd spryker-claude-skill && zip -r spryker-development.zip spryker-development
   ```
2. Upload the ZIP file in claude.ai under **Settings → Capabilities → Skills**.

### Verifying the installation

Restart Claude Code (or open a new session) and ask:

```
Which skills are available to you?
```

`spryker-development` should appear in the list.

### Updating

```bash
cd ~/src/spryker-claude-skill && git pull      # for the symlink installation
# for a copy: clone again and overwrite the folder
```

---

## Usage

### Automatic

The skill activates automatically as soon as a task involves Spryker – even if only terms like "Spryker", "Pyz", `docker/sdk cli`, "Zed", "Yves" or "Glue" come up. Just work in your Spryker project and describe the task:

```
Create a project module ProductBadge with a table, a Facade, a Back Office
list page and a Glue Storefront resource that returns the badges of a product.
```

```
Publish the badges to Redis and show them on the product detail page
with a widget.
```

```
Add a condition to the OMS process that only confirms orders
once a payment exists.
```

```
I need an additional column on spy_product_abstract – how do I add it
without touching the core?
```

```
Write a Codeception facade test and a Glue Cest for the module
and run PHPStan and the sniffers.
```

### Explicit

You can also invoke the skill directly:

```
/spryker-development
```

or in the prompt: *"Use the spryker-development skill and …"*

### What Claude does

Claude follows the standard workflow defined in the skill:

1. **Plan** – apply the decision guides, load the matching reference, copy a template from `templates/`.
2. **Write code** – module under `src/Pyz/<Layer>/<Module>`, transfers in `*.transfer.xml`, schema in `*.schema.xml`.
3. **Generate, migrate, register** – `transfer:generate`, `propel:install`, plugin entries in the Pyz DependencyProvider, router/navigation/OMS caches, `queue:setup`, frontend build.
4. **Test** – `docker/sdk testing`, Codeception suites.
5. **Check** – sniffers, PHPStan, evaluator, review checklist.
6. **Ship** – release/deployment checklist.

### Notes on the environment

The commands in the skill are written for the Spryker Docker SDK (folder `docker/` in the project), e.g.:

```bash
docker/sdk cli console transfer:generate
docker/sdk cli vendor/bin/yves router:cache:warm-up
docker/sdk testing
```

Some values come from the reference setup the skill was created with (b2b-demo-shop, `deploy.dev.yml`, regions EU and US in Dynamic Store mode, hosts such as `yves.eu.spryker.local`) and are marked accordingly in the references. If your project uses a different deploy file, B2C or marketplace demo shop, or another release, just tell Claude in the prompt – the architecture and extension mechanisms apply regardless.

---

## Topic overview

| Area | References |
|---|---|
| **Fundamentals** | 01 Architecture · 02 Project module · 03 Extending the core · 04 Transfers · 05 Propel & persistence · 06 Configuration & environments |
| **Data flow** | 07 Publish & Synchronization · 08 Client: Storage, Search, ZedRequest · 09 Data Import |
| **Commerce** | 10 OMS & state machine · 11 Cart, price, checkout |
| **APIs** | 12 Glue Storefront API · 13 Glue Backend API, authorization, Dynamic Entity |
| **Storefront (Yves)** | 14 Controllers, routing, Twig · 15 Atomic Frontend, themes, build · 16 Widgets · 17 CMS |
| **Back Office & B2B** | 18 Back Office · 19 Multi-store, locales, translations · 20 B2B permissions · 21 Security & session |
| **Operations** | 22 Console, cron jobs, queue workers · 23 Errors, logging, debugging · 24 Mail, message broker, ACP · 25 Performance & caching |
| **Quality & lifecycle** | 26 Tests (Codeception, Testify) · 27 Coding guidelines & static analysis · 28 Upgradability · 29 Feature integration · 30 Docker SDK & local workflow |

Each reference starts with a status line (release, verification level) and ends with a **"Sources"** section that also lists remaining open gaps.

---

## Templates

All scaffolds share the running example module `ProductBadge` (a badge such as "New" or "Sale" attached to an abstract product). Each template has its own `README.md` with the target file list, placeholders, registration steps, the command sequence and its verification status. All twelve templates were installed into a 202610.0 shop, executed end to end and removed again.

| Template | Contents |
|---|---|
| `zed-module` | Zed Business/Communication/Persistence, Facade, Factory, DependencyProvider, Config, Repository, EntityManager, mapper |
| `transfer-and-propel` | Transfer XML, Propel schema (incl. storage table) |
| `publisher-and-synchronization` | Publisher and synchronization plugins, storage writer, Client with storage reader |
| `data-import-step` | Data importer with steps, writer and CSV |
| `oms-process` | OMS process XML with command, condition and timeout, plus a process test |
| `glue-resource-storefront` | Glue Storefront resource (classic GlueApplication) |
| `glue-resource-backend` | Glue Backend API resource |
| `yves-controller-route` | Yves controller, RouteProvider plugin, Twig view |
| `widget` | Yves widget |
| `twig-molecule-organism` | Atomic Frontend molecule and organism with Twig, SCSS and TypeScript |
| `back-office-page` | Back Office list/create pages, table, form, `navigation.xml`, ACL installer, translations |
| `codeception-test` | Codeception facade test with data helper and Glue API Cest |

---

## Checklists

- **`checklists/review.md`** – code review: architecture and code style, static analysis, tests, performance, security and session, configuration, errors/logging/mail, upgradability and workflow.
- **`checklists/upgradability.md`** – DependencyProvider rules (evaluator checkers), extension strategy, evaluator run, Composer and versions, security and platform, safeguards.
- **`checklists/release-deployment.md`** – before shipping: quality and tests as a gate, configuration and secrets, build and caches, database and data, operations.

---

## Quality standard and known gaps

- Content is verified against the **source code of Spryker b2b-demo-shop 202610.0**.
- All commands in the skill were executed live in the Docker SDK container. Commands that destroy data (`docker/sdk reset`, `clean-data`, `up --data`, `propel:tables:drop` …) or require SaaS/AWS were deliberately not executed and are marked as such.
- All twelve templates were installed, run end to end (migration, Publish & Sync to Redis, import, Glue via curl, Yves route and widget, Back Office with ACL, OMS with a real order, frontend build, tests) and removed again.
- **Known gap:** the pages on docs.spryker.com could mostly be fetched only as navigation. The content is therefore **code-verified, docs not cross-checked**; deviations between the docs ("latest") and the code are marked. Remaining open points are listed in the "Sources" section at the end of each reference.
- **Out of scope:** Merchant Portal/Marketplace, Oryx and the Spryker API Platform (not installed in the release). The Spryker Code Upgrader is a CI/SaaS service and cannot be checked locally.

For newer Spryker releases most of the content remains valid; Claude is instructed to check signatures against the installed code under `vendor/` and `src/Pyz` when in doubt.

---

## Customizing and extending

- **Your own conventions** (module prefixes, agency standards, CI setup) can be added to `SKILL.md` under "Core principles".
- **New topics**: create them as `references/NN-topic.md` and link them in the table of contents in `SKILL.md`.
- The `description` in the frontmatter of `SKILL.md` controls when Claude loads the skill automatically – add new keywords there (limit: 1024 characters).

Issues and pull requests are welcome.
