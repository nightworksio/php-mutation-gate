# Jenkins

`init --ci=jenkins` prints a declarative pipeline to add to the Jenkinsfile
`ci.jenkins.definition` names, `Jenkinsfile` by default, which it never edits.
Run it from a multibranch pipeline: Jenkins names the branch in `BRANCH_NAME`,
a pull request in `CHANGE_ID` and its target in `CHANGE_TARGET`, and a tag in
`TAG_NAME`, and only a multibranch project sets them. A config `init` writes
sets `ci.defaultBranch`, since Jenkins names no default branch, and where a
config is kept without it, `init` says to set it. The pipeline takes the
Pipeline Utility Steps plugin, for `readJSON`, and the Credentials Binding
plugin.

`plan --ci=jenkins` prints the plan as JSON. The pipeline reads it with
`readJSON`, hands `parallel` one closure per shard, each on an agent of its
own and naming its shard in `SHARD`, and passes files between them with
`stash` and `unstash`. The verdict runs in `post { always { … } }`, whatever
the shards did. The pipeline's `cron` trigger starts the full run on the
default branch twice a week. The plan fetches the branch it compares against
with `git fetch`, so leave the clone whole and let the agents fetch from
`origin`.

Jenkins has no cache, so the ledger lives in S3. Its keys, `AWS_ACCESS_KEY_ID`
and `AWS_SECRET_ACCESS_KEY`, are the username and password of
the credentials `mutation-gate-store`, which only the default branch's verdict binds. A
credential reaches every build of the folder that holds it, and a branch's
author writes its Jenkinsfile, so any branch whose Jenkinsfile Jenkins runs can
bind it. To keep the keys from other branches, hold them in a folder whose
multibranch pipeline builds the default branch alone, apart from the one that
builds every other branch and pull request; otherwise trust only authors who
may push to the default branch, in the branch source's trust setting. Every
other step reads the default branch's ledger through
`proofs.store.with.publicUrl`, so set it as [proofs and trust](../concepts/proofs-and-trust.md#pull-requests-from-forks)
describes. Jenkins hands a
build no credential of its own, so the gate withholds nothing more from the
tests than every run does; add any other credential the pipeline binds to
`runner.withhold`.
