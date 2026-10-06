<?php
namespace Database\Factories;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
class UserFactory extends Factory {
 protected $model = User::class;
 public function definition(): array {
  return ['username'=>fake()->unique()->userName(), 'full_name'=>fake()->name(), 'password_hash'=>Hash::make(bin2hex(random_bytes(16))), 'role'=>'staff', 'active'=>false];
 }
}
