# About

This is a very simple and heavily untested exporter for ActiveCollab. It produces
a CSV file suitable for importing a project into Jira.

The code is provided as-is. I will not be accountable for anything you do or break using it.
So read it and make sure it suits you.

I will not provide support or any updates, as I am not using ActiveCollab any more. Merge
requests will be welcome though (I will not be able to test them).

# Usage

Edit `jira/create_export.php` to fit your needs. You will most certainly need to only change the configuration on top.

Then upload the whole `jira/`-folder to your server and open `https://your-domain.name/jira/create_export.php`,
enter all details (fill out the form) and grab a nice CSV export.

**Note:** The script is not particularly well protected. It will allow an export of ALL your projects. Be sure to
set the secret to something safe and only keep the script on your server for as short as possible. I suggest
using a local separate installation where nobody can access the script. If your data is stolen it is your fault
alone!

# About the export

* Task lists are exported as Epics to Jira (not optimal, but I think the best way)
* Tickets will be exported including subtasks (type "Task" and "Sub-task")
* The state of the ticket can either be "Open"/"To Do" or "Done"
  * When "Done" the Resolution is set to "Done", too
* Attachments to tickets and comments are exported as Jira issue attachments (no comment attachments possible)
* Important tickets get the priority "Highest"
* Subscribers will be exported as Jira watchers
* Estimation will be exported as the "Original Estimation", NO logged work will be exported
* The export will try to preserve the ticket number when providing the Jira projectKey
  * If not provided some relations between tickets will fail
  * Meaning: Always provide the projectKey
* Mentions will be imported
  * We made the assumption that username==email
  * If thats not right for you, change the code!
* Labels will be exported
  * Note: Jire will not import Labels containing whitespace correctly
* ActiveCollab texts (task description and comments) will be converted to Markdown and then processed to be
  somewhat Jira compatible. I did not put much effort into this and Jira does not use Markdown. But this worked
  fine for us for most cases. Change this if neccessary.
* Trashed items will not be exported.

## Required configuration

* Set `$SECRET` to something only you know, don't share this!
* Set `$AC_BASE_URL` to your ActiveCollab installation URL (including a trailing slash)

## The export form

* `Secret`: The Secret you configured. This is a minimal and sloppy way to prevent other using the script. (And **NOT** really secure)
* `projectId`: The ActiveCollab project ID. See URL when opening a project in ActiveCOllab, the numeric bits.
* `projectKey`: The project key the project will have in Jira. This is optional, but really set it, I mean, **REALLY**.
* `openStatus`: The status your open issues should have. Could be `Open` or `To Do`.

# About the import

* Use the provided file `CSV-configuration.txt` to have a pre-configuration for the import

## After the import

* Check users in the Jira project, all users will be "Developer"'s, probable change that
* Archive the ActiveCollab project ;-)
