<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
class DatabaseSeeder extends Seeder {
 public function run(): void {
  DB::table('project_number_sequences')->insertOrIgnore(['sequence_name'=>'projects','next_number'=>1]);
  // Employee accounts are created interactively using tracker:create-admin.
 }
}
