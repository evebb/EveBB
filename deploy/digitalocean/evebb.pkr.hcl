# eveBB — DigitalOcean Marketplace 1-Click image build (Packer, HCL2)
#
# Usage:
#   export DIGITALOCEAN_TOKEN=<your DO API token>
#   cd deploy/digitalocean
#   packer init .
#   packer build evebb.pkr.hcl
#
# The build boots a temporary $6 droplet from a clean Ubuntu 24.04 base,
# runs the eveBB provisioner plus DigitalOcean's own cleanup/validation
# scripts, then snapshots it. Submit the snapshot at
# https://cloud.digitalocean.com/vendorportal
#
# cleanup.sh and img_check.sh come from DigitalOcean's vendor tooling:
#   https://github.com/digitalocean/marketplace-partners
# (fetched at build time below, so they are always current).

packer {
  required_plugins {
    digitalocean = {
      version = ">= 1.0.0"
      source  = "github.com/digitalocean/digitalocean"
    }
  }
}

variable "do_token" {
  type      = string
  default   = env("DIGITALOCEAN_TOKEN")
  sensitive = true
}

source "digitalocean" "evebb" {
  api_token     = var.do_token
  image         = "ubuntu-24-04-x64"
  region        = "lon1"
  size          = "s-1vcpu-1gb"
  ssh_username  = "root"
  snapshot_name = "evebb-{{timestamp}}"
}

build {
  sources = ["source.digitalocean.evebb"]

  # Let the droplet finish its boot-time work (cloud-init, the DO agent
  # install, unattended-upgrades) before we touch the package system -
  # otherwise apt races the boot chores for the dpkg lock and exits 100.
  provisioner "shell" {
    inline = [
      "cloud-init status --wait || true",
      "mkdir -p /tmp/evebb-build"
    ]
  }
  provisioner "file" {
    source      = "../files/nginx-evebb.conf"
    destination = "/tmp/evebb-build/nginx-evebb.conf"
  }
  provisioner "file" {
    source      = "../files/99-evebb-motd"
    destination = "/tmp/evebb-build/99-evebb-motd"
  }
  provisioner "file" {
    source      = "../scripts/02-evebb-firstboot.sh"
    destination = "/tmp/evebb-build/02-evebb-firstboot.sh"
  }

  # Install the stack + eveBB
  provisioner "shell" {
    script = "../scripts/01-setup-evebb.sh"
  }

  # DigitalOcean's marketplace cleanup + validation (their repo names them
  # 90-cleanup.sh / 99-img-check.sh; cleanup wipes /tmp, so the scripts
  # live in /root while they run)
  provisioner "shell" {
    inline = [
      "curl -fsSL -o /root/cleanup.sh https://raw.githubusercontent.com/digitalocean/marketplace-partners/master/scripts/90-cleanup.sh",
      "curl -fsSL -o /root/img_check.sh https://raw.githubusercontent.com/digitalocean/marketplace-partners/master/scripts/99-img-check.sh",
      "rm -rf /tmp/evebb-build",
      "bash /root/cleanup.sh",
      "bash /root/img_check.sh",
      "rm -f /root/cleanup.sh /root/img_check.sh"
    ]
  }
}
