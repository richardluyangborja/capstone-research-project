react on /frontend
laravel on /backend
docker compose with db service as the local postgres db in the project root
use jwt as auth strategy. avoid cookies since its complex on a different origin setups like this.
do not use faker on data seeds, the system will be seeded on prod since its in a proposal stage and not actual business yet.
use stable libraries. use lucide icons for icon lib.
git repo on the project root that includes both /frontend and /backend to track changes. the remote repo is already added. use branching (feature->main)
