# Project roadmap

This roadmap describes intended project direction. Items are not commitments to a release date. The GitHub issue tracker is the source of truth for scoped work and current ownership.

## Current release line

**1.0.0-beta.7** is pre-release software. It provides tour catalogue, departure inventory, booking requests, traveller pricing snapshots, itinerary and gallery presentation, moderated reviews, accessibility improvements, privacy export/erasure hooks, and a read-only REST API.

It does not process payments, calculate taxes, provide customer accounts, or replace an operator's booking approval and fulfilment processes. Browser-driven end-to-end submission and manual assistive-technology testing remain outstanding.

## Near-term priorities

1. **Release confidence**
   - Add browser-driven tests for visitor booking and review flows.
   - Document a repeatable staging-site acceptance checklist.
   - Review supported WordPress/PHP versions and run the full CI matrix before each beta.
2. **Contributor experience**
   - Improve REST API examples and integration recipes.
   - Complete community translations with native-speaker review.
   - Keep issues small, reproducible, and labelled for first-time contributors.
3. **Operational reliability**
   - Document email/SMTP setup, backups, privacy responsibilities, and troubleshooting.
   - Review database upgrade and uninstall/retention behavior.
4. **Integrations (after core stability)**
   - Design optional payment-provider adapters behind a clear interface.
   - Keep card data and payment credentials outside the plugin; use provider-hosted checkout/tokenization.
   - Add provider-specific integration tests before calling any gateway supported.

## Later / exploratory

- Customer self-service booking lookup and account workflows.
- Additional operator-facing reporting and export tools.
- More documented REST API examples and integration compatibility guidance.

## How to propose work

Search [open issues](https://github.com/azeddinee/opentrip-engine/issues) before opening a new one. For substantial features, start with an issue describing the operator problem, proposed workflow, security/privacy implications, and acceptance criteria. Please avoid large changes without prior discussion.

## Status labels

- **Done** means merged and covered by the stated checks; it does not imply production certification.
- **In progress** means active work exists in a linked issue or pull request.
- **Planned** means an idea has been accepted for exploration, not scheduled.
