# Team repo statistics
Parses Git logs for commits by specific people over a set time and formats the
results into a table and summary. Assumes the standard Drupal.Org commit message
format in order to parse the logs. That is: 
(`Issue #nnn By name1, name2, ...: DESCRIPTION`)

## Usage

```
$ ./bin/report [options]
$ ./bin/stats --year=2020 --week=41 --no-interaction --log-only 
```

1. Run `composer install`
1. Modify `committers.yml` and `repos.yml` as needed to match the people you
   want to track, and in which repos.
2. Optionally modify `date.range.yml`. If you don't, you'll need to pass
   `--year` and `--quarter` or `--week` options to the command.
2. Run `./bin/report` to output the table and summary.
3. Alternatively, you can instantiate `Balsama\DoStats\GitLogStats` and poke
   around the issue data with `::getAllIssueData`.

## Options

Run the following for a complete list of options:

```
$ ./bin/report.php --help
```