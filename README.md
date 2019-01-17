# Team repo statistics
Parses Git logs for commits by specific people over a set time and formats the
results into a table and summary.

## Usage
1. Modify `committers.yml`, `date.range.yml`, and `repos.yml` as needed to match
   the people you want to track, the time frame, and in which repos.
2. Run `php report.php` to output the table and summary.
3. Alternatively, you can instantiate `Balsama\DoStats\GitLogStats` and poke
   around the issue data with `::getAllIssueData`.
