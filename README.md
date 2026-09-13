# GLPI Cloud — Azure

Microsoft Azure provider for the `glpicloud` plugin. Auth, enumeration and cost.

Requires `glpicloud` to be installed and active. This plugin has no tables,
menu, rights or settings of its own; storage, entities, projection and money all
belong to the core.

## What it collects

One Azure Resource Graph query per service. Each service asks for its slice with
a `type in~` filter; `other` takes everything the rest did not claim, stored with
its exact Azure type.

| Service | Covers |
|---|---|
| `compute` | VMs, scale sets, disks, snapshots, images, availability sets |
| `network` | VNets, NICs, public IPs, NSGs, load balancers, gateways, firewalls, private endpoints, DNS zones |
| `storage` | Storage accounts, Recovery Services vaults |
| `database` | SQL servers and databases, MySQL/PostgreSQL flexible servers, Cosmos, Redis |
| `containers` | AKS clusters, container registries, container groups |
| `web` | App Service sites and plans, API Management |
| `identity` | Key vaults, user-assigned managed identities |
| `other` | Everything else Azure returned |

Cost comes per resource, per month, from Cost Management. The current month is
flagged provisional so the core never rolls it onto a contract. Charges with no
resource id (marketplace, support, reservations) are kept against the account so
the total reconciles with the invoice.

## Credentials

An Entra ID app registration in the entity's tenant — tenant id, client id,
client secret — plus two read-only role assignments:

```sh
az role assignment create --assignee <client-id> --role Reader \
  --scope /subscriptions/<subscription-id>

az role assignment create --assignee <client-id> --role "Cost Management Reader" \
  --scope /subscriptions/<subscription-id>
```

Assign at a management group instead of a subscription and every subscription
under it is picked up. The sweep enumerates whatever the app can see; disabled
and lapsed subscriptions are kept and marked inactive rather than dropped.

The plugin never writes to Azure. A *Check credentials* button verifies the
grant before anything is stored, including the case where the app authenticates
but holds no role assignment. Access tokens live in memory for the run only.

## Implementation notes

- Every Resource Graph query ends `| order by id asc`. Unsorted paged queries
  repeat and skip rows between pages.
- `Retry-After` is honoured on a throttle. If the wait would run past the
  sweep's budget the request is abandoned; the cursor is checkpointed, so the
  next run resumes.
- `provisioningState` is not used as the resource state — it reports the last
  deployment, and a stopped VM reads `Succeeded`. The state ladder tries power
  state, disk state, replica status and app state first, falling back to
  `provisioningState` last.

## Tests

```bash
tests/run.sh          # pure PHP, no GLPI, no network

docker compose -p glpi exec glpi \
  php /var/www/glpi/plugins/glpicloudazure/tests/http.php   # needs Guzzle, still no network
```

Collectors take a plain callable rather than an HTTP client, so paging,
resumption, budgets and cost-column mapping are testable with fixtures and no
tenant. `descriptor.php` hands the descriptor to glpicloud's own registry.

Not verified against a live Azure tenant.

## Install

```bash
# from the GLPI root — the directory must be named for the plugin key,
# which is not the repository name
git clone https://github.com/bijstaan/glpi-cloud-azure.git plugins/glpicloudazure
php bin/console plugin:install -u glpi glpicloudazure
php bin/console plugin:activate glpicloudazure
```

## Licence

GPL-3.0-or-later, the same licence as GLPI. The plugin is loaded into GLPI's
process and extends its classes, so it is a derivative work. See
[LICENSE](LICENSE).
