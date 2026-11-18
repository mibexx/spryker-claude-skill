---
name: spryker-development
description: Development with Spryker (b2b-demo-shop, release 202610.0) across backend, frontend and Glue API. Use for Spryker modules and the project level Pyz, Zed (Business/Communication/Persistence/Presentation), Yves (controllers, routes, Twig, widgets, themes, Atomic Frontend with molecules/organisms, SCSS, TypeScript components, npm run yves/frontend build), Glue Storefront/Backend API, Client, Facade, Factory, DependencyProvider, plugins/extension points, transfers (transfer.xml), Propel schema, Repository/EntityManager, Publish & Synchronization, Data Import, OMS/state machine, cart/price/checkout, CMS (slots, content items), Back Office (tables, forms, navigation.xml, ACL), multi-store/locales, B2B permissions, security/session, console/cron jobs/queue workers, mail/message broker, performance/caching, Codeception tests, PHPStan/sniffers, upgradability/evaluator, feature integration, Docker SDK/deploy.yml. Also for "change Pyz instead of core", "register a plugin", "docker/sdk cli".
---

# Spryker development (release 202610.0)

This skill guides development, extension and operation of a Spryker project. SKILL.md contains principles, workflow and decision guides. Details are in `references/`, scaffolds in `templates/`, checklists in `checklists/`.

## Status of the knowledge base (transparent)

- **Basis:** installed release `spryker-shop/b2b-demo-shop` **202610.0** (tag commit `d45396b8`, spryker/kernel 3.87.1, spryker/oms 11.55.0, PHP 8.4, Node 24/npm 10, Symfony 6.4). The installed code under `src/Pyz` and `vendor/` takes precedence over the docs.
- **Known gap – docs:** The documentation pages (docs.spryker.com) could mostly be fetched only as navigation. The content is **code-verified, docs not cross-checked**. Deviations between the docs ("latest") and the code are marked in the references.
- **Commands:** All commands in the skill were **executed** in a live verification inside the container against release 202610.0 and carry the marker **live verified (202610.0)**. Deviations found in the process are corrected in the references (e.g. `glue-api:controller:cache:warm-up` without argument, `state-machine:check-condition <Name>`, `code:sniff:style <path>` instead of `-m Pyz.<Module>`, `codecept run -c tests/PyzTest/Zed/<Module>`, `propel:config:convert` broken). **Not executed locally** were commands that destroy data (`docker/sdk reset`, `clean-data`, `up --data`, `propel:tables:drop`, `propel:database:drop`, `propel:sql:insert`) or require SaaS/AWS; each is marked with the reason. **Caution:** `docker/sdk demo [<section>]` is a forced rebuild and drops all tables of the region (see [Docker SDK and workflow](references/30-docker-sdk-workflow.md)). The evaluator ran inside the container (→ [Upgradability](references/28-upgradability.md)).
- **OMS:** Content is code-verified and checked live with a real order; remaining open points are marked in the reference (→ [OMS](references/10-oms-state-machine.md)).
- **Templates:** All twelve templates were **walked through** in release 202610.0: temporarily installed into the shop, registrations added to the real `Pyz` files and the documented sequence executed (`transfer:generate`, a real Propel migration, Publish & Sync down to Redis, Data Import, Glue Storefront/Backend via curl, Yves route and widget, Back Office page with ACL, OMS process with a real order, frontend build, Codeception facade test and Glue Cest), then fully removed again. Errors found in the process are fixed in the templates (among others `queue_pool` in the storage schema, trigger plugin with entity transfers, validator without tax-set observer, `@method getRepository()` in the GatewayController, DataBuilder `strict`). Every template README states its verification status.
- **Out of scope:** Merchant Portal/Marketplace, Oryx and the Spryker API Platform (`spryker/api-platform` is **not installed** in the release, see [Glue Backend](references/13-glue-backend.md)). The Spryker Code Upgrader is a CI/SaaS service and cannot be checked locally.

## Core principles

1. **Applications and layers.** Six namespace layers per module: `Yves`, `Zed`, `Glue`, `Client`, `Service`, `Shared` (mirrored in `src/Pyz/`). Entry points under `public/<App>/index.php`: Yves (Storefront), Backoffice (Zed UI), BackendGateway (Zed internal), Glue (legacy REST), GlueStorefront, GlueBackend (→ [Architecture](references/01-architecture.md)).
2. **Allowed communication.** Yves/Glue → **Client** → Storage (Redis/Valkey) | Search (OpenSearch/Elasticsearch) | ZedRequest → Zed gateway. Yves never calls Zed classes directly. Zed module → other Zed module only via its **Facade**. At project level the DependencyProvider fetches the foreign Facade or Client directly via the locator (no bridge); the factory getter is typed to the foreign module's interface (e.g. `ProductFacadeInterface`). Bridges are a pure core pattern (→ [Project module](references/02-project-module.md)). The rule `ProjectNoBridgeRule` exists only in the project ruleset of the Architecture Sniffer (call: `vendor/bin/phpmd src/Pyz text vendor/spryker/architecture-sniffer/src/Project/ruleset.xml`); `code:sniff:architecture` uses the core ruleset by default and does not report bridges. Data crosses module boundaries only as **transfer objects**.
3. **Zed module layers.** Four in the release: `Business` (Facade, BusinessFactory, models), `Communication` (controllers, plugins, tables, console), `Persistence` (Repository, EntityManager, mapper, Propel schema), `Presentation` (Twig). The public API is only the Facade/the Client (with `@api`); everything else is private.
4. **Change Pyz, not the core.** Never edit in `vendor/`. Name resolution: the project namespace (`Pyz`) is resolved **before** the core namespaces (`SprykerShop`, `SprykerEco`, `Spryker`, `SprykerSdk`). A Pyz Facade/Factory/Config/DependencyProvider with the same name replaces the core class automatically (the Pyz class **extends** the core class).
5. **Extension strategies in this order of preference:** configuration → plug and play (plugin in the Pyz DependencyProvider) → project module → module customization (override a class in Pyz) → module replacement (no upgrade support) (→ [Extending the core](references/03-extending-the-core.md)).
6. **Upgradability.** An overridden core method is an upgrade hotspot. Do not use private core APIs, do not copy core methods/constants, keep plugin registration flat. Run the evaluator regularly (→ [Upgradability](references/28-upgradability.md), [checklist](checklists/upgradability.md)).
7. **Coding guidelines.** PHPStan, Code Sniffer (`phpcs.xml`) and Architecture Sniffer (core ruleset via `code:sniff:architecture`, additionally the project ruleset via `phpmd`) before every commit (→ [Coding guidelines](references/27-coding-guidelines.md)).
8. **Logic belongs in the Business layer**, not in controllers, plugins or Twig. Plugins only delegate to the Facade/Factory of their own module.
9. **Save resources.** Yves reads only from Redis/Search (Client), no Zed calls in the request path; batch instead of N+1 (→ [Performance](references/25-performance-caching.md)).

## Standard development workflow

All commands run in the container: `docker/sdk cli <command>`, console `docker/sdk cli console <cmd>` (short form `docker/sdk console <cmd>`). The commands in the table and the `docker/sdk` lifecycle commands (`up`, `up --build`, `up --assets`, `start`, `stop`, `restart`, `down`, `testing`) were executed and are **live verified (202610.0)**; `docker/sdk up` needs a TTY (without one: `failed to get console`). Details and order in [Docker SDK and workflow](references/30-docker-sdk-workflow.md).

1. **Plan:** Apply the decision guides below, read the matching reference, copy a template (`templates/`, replace the placeholder `ProductBadge`).
2. **Write code:** Module under `src/Pyz/<Layer>/<Module>`, transfers in `*.transfer.xml`, schema in `*.schema.xml`.
3. **Generate/migrate/register** depending on the kind of change:

| Change | Commands (each `docker/sdk cli console …`) |
|---|---|
| `*.transfer.xml` | `transfer:generate` (tests: `transfer:databuilder:generate`) |
| `*.schema.xml` | `propel:schema:copy` → `propel:model:build` → `propel:diff` → `propel:migrate` → `transfer:entity:generate` → `transfer:generate`; short form `propel:install` |
| New classes/modules | `composer dump-autoload` (production: `cache:class-resolver:build`) |
| Plugin | Entry in the **Pyz DependencyProvider** of the target module (no command); check: `dev:plugin-usage:dump` |
| New route/controller | Yves: `docker/sdk cli vendor/bin/yves router:cache:warm-up`; Back Office: `router:cache:warm-up:backoffice`; gateway: `router:cache:warm-up:backend-gateway` |
| `navigation.xml` | `navigation:build-cache` |
| OMS process XML | `oms:process-cache:warm-up` |
| Glue resource | `rest-api:build-request-validation-cache`, `vendor/bin/glue glue-api:controller:cache:warm-up` **without argument** (an application name overwrites the shared cache with only that application's share) |
| Zed translations | `translator:generate-cache` |
| Search mapping | `search:setup:sources` |
| New queue | `queue:setup` |
| Published data missing | `publish:trigger-events` |
| SCSS/TS | `frontend:yves:build`, `frontend:zed:build` (details [Frontend](references/15-yves-frontend-atomic.md)) |
| Unclear cache state | `cache:empty-all` |

   Full rebuild after Pyz changes (without DB rebuild): `docker/sdk cli vendor/bin/install -r docker -s build -s build-development`.
4. **Test:** `docker/sdk testing` or Codeception suites ([Tests](references/26-tests.md), [template](templates/codeception-test/README.md)).
5. **Check:** sniffers, PHPStan, evaluator, [review checklist](checklists/review.md).
6. **Ship:** [release/deployment checklist](checklists/release-deployment.md).

## Decision guides

**Plugin vs. inheritance vs. configuration** (keep this order)
- Is it about a **value** (limit, list, flag, event list)? → Extend `Pyz\…\<Module>Config`.
- Is there an **extension point** (plugin stack, interface in `*-extension`)? → Write a plugin and register it in the Pyz DependencyProvider (define the stack completely in Pyz or use `array_merge(parent::…)`; removing = define the stack without the plugin).
- Extension point missing? → Ask Spryker; workaround: override the factory in Pyz and add a new `create…()` method (customization). Pin the module version with `~` (→ [Extending the core](references/03-extending-the-core.md)).
- Only if nothing fits: module replacement (no upgrade support).

**Facade vs. Client**
- Zed-internal (module A → module B): Facade of B, in the project directly via the locator in the DependencyProvider (no bridge; `ProjectNoBridgeRule` of the project ruleset reports bridges in project modules, `code:sniff:architecture` does not check this by default). Type the factory getter to the foreign module's `…FacadeInterface`.
- Yves/Glue need **read data** (products, CMS, prices): **Client** on Storage/Search (data via Publish & Sync).
- Yves/Glue need **write access/logic** (register customer, place order): Client with Zed stub (`ZedRequest` → Zed gateway). Use Zed requests from Yves sparingly (performance, → [Client](references/08-client-storage-search.md)).
- Never: Yves/Glue → Zed Facade directly.

**Own module vs. project extension**
- New business logic/data/tables/UI → **new Pyz module** ([Project module](references/02-project-module.md), [template](templates/zed-module/README.md)). High upgrade safety.
- Adjusting existing behavior → config/plugin in the Pyz DependencyProvider of the core module.
- Adding a column to a core schema → Pyz schema with the same table name (`src/Pyz/Zed/<Module>/Persistence/Propel/Schema/`, merge, [Propel](references/05-propel-persistence.md)).

**Glue Storefront vs. Backend API**
- Audience **customer/shop frontend** (customer OAuth) → Storefront. In the release almost all commerce resources live in the **classic GlueApplication** (`glue.*`, `ResourceRoutePlugin`); the decoupled `GlueStorefrontApiApplication` contains only a few resources (token, stores, multi-factor auth). Details [Glue Storefront](references/12-glue-storefront.md).
- Audience **Back Office user/system-to-system** → Glue Backend (`GlueBackendApiApplication`, `AbstractResourcePlugin`, OAuth backend/API key). Details [Glue Backend](references/13-glue-backend.md).
- API Platform: not installed, no recommendation for new endpoints.

**Repository/EntityManager vs. QueryContainer**
- New modules: **Repository** (read, returns transfers) + **EntityManager** (write) + mapper. QueryContainer is legacy (no `@deprecated` annotation in the kernel, used in older Pyz modules); only for extending existing legacy modules ([Propel](references/05-propel-persistence.md)).

**Further distinctions**
- Bring data from Zed to Yves/Glue → Publish & Sync ([Publisher](references/07-publish-sync.md)), not ZedRequest.
- Bulk import/master data → Data Import ([Data Import](references/09-data-import.md)).
- Status flow of an order → OMS; generic state machines → StateMachine ([OMS](references/10-oms-state-machine.md)).
- Permissions in the shop (company roles) → [B2B permissions](references/20-b2b-permissions.md); permissions in the Back Office (ACL) → [Back Office](references/18-back-office.md).

## Table of contents

### References (`references/`)
| No. | Topic | File |
|---|---|---|
| 01 | Architecture: applications, layers, core vs. Pyz | [01-architecture.md](references/01-architecture.md) |
| 02 | New project module (Facade, Factory, DependencyProvider, Config; bridges only as a core pattern) | [02-project-module.md](references/02-project-module.md) |
| 03 | Extending the core: plugins, extension points, inheritance | [03-extending-the-core.md](references/03-extending-the-core.md) |
| 04 | Transfer objects | [04-transfer.md](references/04-transfer.md) |
| 05 | Propel schema and persistence | [05-propel-persistence.md](references/05-propel-persistence.md) |
| 06 | Configuration and environments | [06-configuration.md](references/06-configuration.md) |
| 07 | Publish & Synchronization (write side) | [07-publish-sync.md](references/07-publish-sync.md) |
| 08 | Client: Storage, Search, ZedRequest (read side) | [08-client-storage-search.md](references/08-client-storage-search.md) |
| 09 | Data Import | [09-data-import.md](references/09-data-import.md) |
| 10 | OMS and state machine | [10-oms-state-machine.md](references/10-oms-state-machine.md) |
| 11 | Cart, price, checkout | [11-cart-price-checkout.md](references/11-cart-price-checkout.md) |
| 12 | Glue Storefront API | [12-glue-storefront.md](references/12-glue-storefront.md) |
| 13 | Glue Backend API, authorization, Dynamic Entity | [13-glue-backend.md](references/13-glue-backend.md) |
| 14 | Yves: controllers, routing, Twig extensions | [14-yves-controller-routing.md](references/14-yves-controller-routing.md) |
| 15 | Yves Atomic Frontend, themes, build | [15-yves-frontend-atomic.md](references/15-yves-frontend-atomic.md) |
| 16 | Widgets | [16-widgets.md](references/16-widgets.md) |
| 17 | CMS: pages, blocks, content items, slots | [17-cms.md](references/17-cms.md) |
| 18 | Back Office: pages, tables, forms, navigation, ACL | [18-back-office.md](references/18-back-office.md) |
| 19 | Multi-store, regions, locales, translations | [19-multi-store-i18n.md](references/19-multi-store-i18n.md) |
| 20 | B2B permissions in the Storefront | [20-b2b-permissions.md](references/20-b2b-permissions.md) |
| 21 | Security and session | [21-security-session.md](references/21-security-session.md) |
| 22 | Console, cron jobs, queue workers | [22-console-cron-queue.md](references/22-console-cron-queue.md) |
| 23 | Error handling, logging, debugging | [23-errors-logging-debugging.md](references/23-errors-logging-debugging.md) |
| 24 | Mail, message broker, ACP | [24-mail-message-broker.md](references/24-mail-message-broker.md) |
| 25 | Performance and caching | [25-performance-caching.md](references/25-performance-caching.md) |
| 26 | Tests (Codeception, Testify) | [26-tests.md](references/26-tests.md) |
| 27 | Coding guidelines, static analysis | [27-coding-guidelines.md](references/27-coding-guidelines.md) |
| 28 | Upgradability: evaluator, module versions | [28-upgradability.md](references/28-upgradability.md) |
| 29 | Integrating features, dependencies | [29-feature-integration.md](references/29-feature-integration.md) |
| 30 | Docker SDK, deploy.yml, install recipes, local workflow | [30-docker-sdk-workflow.md](references/30-docker-sdk-workflow.md) |

### Templates (`templates/`, shared running example: module `ProductBadge`)
| Template | Contents |
|---|---|
| [zed-module](templates/zed-module/README.md) | Zed Business/Communication/Persistence, Facade, Factory, DependencyProvider, Config, Repository, EntityManager, mapper |
| [transfer-and-propel](templates/transfer-and-propel/README.md) | Transfer XML, Propel schema (incl. storage table) |
| [publisher-and-synchronization](templates/publisher-and-synchronization/README.md) | Publisher and synchronization plugins, storage reader |
| [data-import-step](templates/data-import-step/README.md) | Data importer with steps and writer |
| [oms-process](templates/oms-process/README.md) | OMS process XML with command, condition, timeout |
| [glue-resource-storefront](templates/glue-resource-storefront/README.md) | Glue resource (Storefront) |
| [glue-resource-backend](templates/glue-resource-backend/README.md) | Glue resource (Backend) |
| [yves-controller-route](templates/yves-controller-route/README.md) | Yves controller, RouteProvider, Twig view |
| [widget](templates/widget/README.md) | Yves widget |
| [twig-molecule-organism](templates/twig-molecule-organism/README.md) | Twig molecule/organism with index.ts and SCSS |
| [back-office-page](templates/back-office-page/README.md) | Back Office page with navigation.xml entry and ACL note |
| [codeception-test](templates/codeception-test/README.md) | Codeception test |

### Checklists (`checklists/`)
[Review](checklists/review.md) · [Upgradability](checklists/upgradability.md) · [Release/deployment](checklists/release-deployment.md)

## Guide by task

- **New feature as a project module:** 02 → 04 → 05 → (07, 08) → controller/resource (14 / 12 / 13 / 18) → 26.
- **Change core behavior:** 03 → 06 → 28.
- **Show data in the shop:** 07 → 08 → 14 → 15/16.
- **Order process:** 11 → 10.
- **Build an API:** 12 or 13 (decision guide above).
- **Operations/troubleshooting:** 30 → 23 → 22 → 25.
- **Integrate a new Spryker feature:** 29 → 28.
