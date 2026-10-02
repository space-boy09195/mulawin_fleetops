# Deployment: Applying the migration chain

For a fresh installation, the complete schema and migration chain
are bundled in [`../db/Mulawin_FleetOps_Full_Setup.sql`].
That bundle was imported successfully into a disposable local MariaDB 10.4.32
schema. Rehearse it against a staging environment matching your deployment
version before using it outside development.

The bundle is **only for a newly created, empty database**. It does not create
the database itself or create login accounts. Select the target database
before importing; afterward, create application accounts using
`auth/seed_users.php`. Do not import the bundle over an existing installation
or run the individual migrations again after importing it.

