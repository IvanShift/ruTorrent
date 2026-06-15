<?php

// rTorrent 0.16 removes deprecated execute2/schedule2 aliases and
// restores ratio group commands to the group.* namespace.
$this->aliases = array_merge($this->aliases,array(
"execute"                       => array( "name"=>"execute", "prm"=>1 ),
"schedule"                      => array( "name"=>"schedule", "prm"=>1 ),
"schedule_remove"               => array( "name"=>"schedule.remove", "prm"=>1 ),
"ratio.min"                     => array( "name"=>"group.seeding.ratio.min", "prm"=>0 ),
"ratio.max"                     => array( "name"=>"group.seeding.ratio.max", "prm"=>0 ),
"ratio.upload"                  => array( "name"=>"group.seeding.ratio.upload", "prm"=>0 ),
"ratio.min.set"                 => array( "name"=>"group.seeding.ratio.min.set", "prm"=>1 ),
"ratio.max.set"                 => array( "name"=>"group.seeding.ratio.max.set", "prm"=>1 ),
"ratio.upload.set"              => array( "name"=>"group.seeding.ratio.upload.set", "prm"=>1 ),
));
