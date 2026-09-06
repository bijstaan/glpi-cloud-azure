# GLPI Cloud — Azure

Microsoft Azure for [glpi-cloud](../glpi-cloud). Auth, enumeration and cost;
nothing else.

This plugin owns everything that speaks Azure's vocabulary and **nothing** that
does not. It has no tables, no menu, no rights and no settings of its own, and
no opinion about entities, projection or money — those belong to the core, and
if any of them turn up here the seam has been broken.

## What it collects

**One Resource Graph query per service.** Resource Graph is why Azure is the
cheap provider to do first: a single endpoint returns every resource in every
region of a subscription, with tags and properties, rather than a per-service
enumeration each with its own pagination. Each of this plugin's services asks
for its own slice with a `type in~` filter, and `other` asks for everything the
rest did not claim — so a failing service cannot cost you the inventory of the
others, and the `service` column means something.

| Service | Covers |
|---|---|
| `compute` | VMs, scale sets, disks, snapshots, images, availability sets |
| `network` | VNets, NICs, public IPs, NSGs, load balancers, gateways, firewalls, private endpoints, DNS zones |
| `storage` | Storage accounts, Recovery Services vaults |
| `database` | SQL servers and databases, MySQL/PostgreSQL flexible servers, Cosmos, Redis |
| `containers` | AKS clusters, container registries, container groups |
| `web` | App Service sites and plans, API Management |
| `identity` | Key vaults, user-assigned managed identities |
| `other` | **everything else Azure returned**, stored with its exact type |

That last row is the point. A provider that only records the types it has a
mapping for produces an inventory that is quietly wrong, and wrong exactly where
somebody is looking: the odd resource nobody remembered creating.

**Cost**, per resource, per month, from Cost Management — with the current month
flagged provisional so the core never rolls it onto a contract, and charges that
carry no resource id (marketplace, support, reservations) kept against the
account so the total reconciles with the invoice.

## What you have to give it

An Entra ID **app registration** in the customer's tenant — tenant id, client
id, client secret — and two **read-only** role assignments:

```sh
az role assignment create --assignee <client-id> --role Reader \
  --scope /subscriptions/<subscription-id>

az role assignment create --assignee <client-id> --role "Cost Management Reader" \
  --scope /subscriptions/<subscription-id>
```

Assign at a management group instead of a subscription and every subscription
under it is picked up automatically — the sweep enumerates whatever the app can
see, including disabled and lapsed subscriptions, which are kept and marked
inactive rather than dropped.

**This plugin never writes to Azure.** Not a tag, not a start, not a stop. The
credential fields say so where you paste one in, and the *Check credentials*
button proves the grant before anything is stored — including the case where the
app authenticates fine and has been given no role assignment at all, which is
the failure that otherwise shows up six hours later as an empty inventory.

Access tokens live in memory for the run and are never written down.

## Three things it gets right on purpose

**Paged queries are sorted.** Every Resource Graph query ends `| order by id
asc`. An unsorted paged query can repeat and skip rows between pages, which here
would look like resources flickering in and out of a customer's estate.

**A throttle is answered with Azure's own number.** `Retry-After` is honoured
rather than guessed at — and if the wait would run past the end of the sweep's
budget, the request is abandoned instead. The cursor is already checkpointed, so
coming back in an hour costs nothing.

**`provisioningState` is not a state.** It says the last deployment worked, not
that anything is running: a stopped VM's is `Succeeded`. The state ladder reads
power state, disk state, replica status and app state first, and falls back to
`provisioningState` last, kept as Azure's own word.

## Tests

```
tests/run.sh                                    # pure PHP, no GLPI, no network
docker compose -p glpi exec glpi \
  php /var/www/glpi/plugins/glpicloudazure/tests/http.php   # needs Guzzle; still no network
```

The collectors take a plain callable rather than an HTTP client, which is what
lets paging, resumption, budgets and the cost column mapping be tested with a
fixture and no tenant. `descriptor.php` goes further and hands the whole
descriptor to **glpi-cloud's own registry**, so "the core would accept this" is
asserted rather than assumed.

Not everything in here has been run against a live tenant.

## Install

```bash
# from the GLPI root — the directory has to be named for the plugin
# key, which is not the repository name
git clone https://github.com/bijstaan/glpi-cloud-azure.git plugins/glpicloudazure
php bin/console plugin:install -u glpi glpicloudazure
php bin/console plugin:activate glpicloudazure
```

## Licence

GNU General Public License, version 3 or later — the same licence as GLPI.
This plugin is loaded into GLPI's process and extends its classes, so it is a
derivative work of GLPI and carries GLPI's licence. See [LICENSE](LICENSE).
