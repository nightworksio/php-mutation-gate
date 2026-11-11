//! The packs in an OCI registry: each class's pack at a base is an artifact
//! tagged `<class>-<base>` in the repository the request names, one gzipped
//! layer holding the pack, and the newest pushed is tagged `<class>` too. A pull reads it without credentials where the
//! registry lets it, and with the token the job hands the gate otherwise; a
//! push merges the entries into the tagged pack and tags the merged one.

use std::env;
use std::fmt::Write as _;
use std::time::Duration;

use outbreak_map::json::Cursor;
use sha2::{Digest as _, Sha256};
use ureq::Agent;

use crate::Failure;
use crate::pack::{self, Pack};
use crate::request::Request;

/// The variable the job hands the registry's token in.
pub const TOKEN: &str = "MUTATION_GATE_REGISTRY_TOKEN";

const MANIFEST: &str = "application/vnd.oci.image.manifest.v1+json";
const ARTIFACT: &str = "application/vnd.nightworksio.outbreak.proofs.v1";
const LAYER: &str = "application/vnd.nightworksio.outbreak.proofs.v1+gzip";
const EMPTY: &str = "application/vnd.oci.empty.v1+json";
const EMPTY_BODY: &[u8] = b"{}";

/// The most bytes a manifest the registry answers may hold.
const MANIFEST_MOST: u64 = 1 << 20;

/// How long one exchange with the registry may take.
const TIMEOUT: Duration = Duration::from_secs(120);

/// Each class's pack at the request's base pulled from the registry into its path: how many were.
///
/// # Errors
///
/// Fails where the request names no registry, or a pack read cannot be written.
pub fn pull(request: &Request) -> Result<usize, Failure> {
    let registry = Registry::named(request)?;
    let mut pulled = 0;

    for (class, into) in &request.packs {
        if let Some(found) = registry.pack(request, class, Access::Pull)? {
            let bytes = found.bytes(request.limits)?;

            if let Some(parent) = std::path::Path::new(into).parent() {
                std::fs::create_dir_all(parent).map_err(|error| {
                    Failure::Io(format!("cannot make {}: {error}", parent.display()))
                })?;
            }

            std::fs::write(into, bytes)
                .map_err(|error| Failure::Io(format!("cannot write {into}: {error}")))?;
            pulled += 1;
        }
    }

    Ok(pulled)
}

/// Each class's entries merged into its tagged pack, the merged pack tagged in its place: how many were.
///
/// # Errors
///
/// Fails where the request names no registry, the entries cannot be read, or the registry refuses the push.
pub fn push(request: &Request) -> Result<usize, Failure> {
    let registry = Registry::named(request)?;
    let mut pushed = 0;

    for (class, entries) in &request.packs {
        let bytes = std::fs::read(entries)
            .map_err(|error| Failure::Io(format!("cannot read {entries}: {error}")))?;
        let newer = pack::read(&bytes, request.limits)?;

        if newer.class != *class || newer.base != request.base {
            return Err(Failure::Refused(format!(
                "{entries} is no {class} pack at the request's base"
            )));
        }

        let older = registry
            .pack(request, class, Access::Push)?
            .unwrap_or_else(|| Pack::empty(class, &request.base));
        let merged = older.merged(newer).bytes(request.limits)?;
        registry.tag(&tag(class, &request.base), &merged)?;
        registry.tag(class, &merged)?;
        pushed += 1;
    }

    Ok(pushed)
}

fn tag(class: &str, base: &str) -> String {
    format!("{class}-{base}")
}

/// What a request to the registry may do.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Access {
    Pull,
    Push,
}

impl Access {
    fn scope(self, name: &str) -> String {
        match self {
            Self::Pull => format!("repository:{name}:pull"),
            Self::Push => format!("repository:{name}:pull,push"),
        }
    }
}

/// A repository of a registry, reached over HTTPS.
struct Registry {
    agent: Agent,
    host: String,
    name: String,
    token: Option<String>,
}

impl Registry {
    fn named(request: &Request) -> Result<Self, Failure> {
        let Some(repository) = request.registry.as_deref() else {
            return Err(Failure::Refused("the request names no registry".to_owned()));
        };
        let Some((host, name)) = repository
            .split_once('/')
            .filter(|_| crate::request::is_repository(repository))
        else {
            return Err(Failure::Refused(format!(
                "{repository} names no repository of a registry"
            )));
        };
        let config = Agent::config_builder()
            .http_status_as_error(false)
            .timeout_global(Some(TIMEOUT))
            .build();

        Ok(Self {
            agent: config.into(),
            host: host.to_owned(),
            name: name.to_owned(),
            token: env::var(TOKEN).ok().filter(|token| !token.is_empty()),
        })
    }

    fn url(&self, path: &str) -> String {
        format!("https://{}/v2/{}/{path}", self.host, self.name)
    }

    /// The pack of a class at the request's base the registry holds; none where it holds none.
    fn pack(
        &self,
        request: &Request,
        class: &str,
        access: Access,
    ) -> Result<Option<Pack>, Failure> {
        let bearer = self.bearer(access)?;
        let named = if request.newest {
            class.to_owned()
        } else {
            tag(class, &request.base)
        };
        let manifest = self.get(
            &self.url(&format!("manifests/{named}")),
            bearer.as_deref(),
            MANIFEST_MOST,
        )?;
        let Some(manifest) = manifest else {
            return Ok(None);
        };
        let digest = layer_of(&manifest)?;
        let Some(bytes) = self.get(
            &self.url(&format!("blobs/{digest}")),
            bearer.as_deref(),
            request.limits.packed + 1,
        )?
        else {
            return Err(Failure::Io(format!("the registry holds no blob {digest}")));
        };

        if sha256(&bytes) != digest {
            return Err(Failure::Refused(format!(
                "the blob the registry answered is not {digest}"
            )));
        }

        let read = pack::read(&bytes, request.limits)?;

        Ok((read.class == class && (request.newest || read.base == request.base)).then_some(read))
    }

    /// The pack's bytes tagged, its layer and the empty config uploaded first.
    fn tag(&self, tag: &str, bytes: &[u8]) -> Result<(), Failure> {
        let bearer = self.bearer(Access::Push)?;
        let layer = self.upload(bearer.as_deref(), bytes)?;
        let config = self.upload(bearer.as_deref(), EMPTY_BODY)?;
        let manifest = format!(
            r#"{{"schemaVersion":2,"mediaType":"{MANIFEST}","artifactType":"{ARTIFACT}","config":{{"mediaType":"{EMPTY}","digest":"{config}","size":{}}},"layers":[{{"mediaType":"{LAYER}","digest":"{layer}","size":{}}}]}}"#,
            EMPTY_BODY.len(),
            bytes.len()
        );
        let mut put = self
            .agent
            .put(&self.url(&format!("manifests/{tag}")))
            .header("Content-Type", MANIFEST);

        if let Some(bearer) = &bearer {
            put = put.header("Authorization", bearer);
        }

        let response = put
            .send(manifest.as_bytes())
            .map_err(|error| Failure::Io(format!("cannot tag {tag}: {error}")))?;

        if response.status().is_success() {
            Ok(())
        } else {
            Err(Failure::Io(format!(
                "the registry refused the tag {tag}: {}",
                response.status()
            )))
        }
    }

    /// A blob uploaded in one request after its upload is begun: its digest.
    fn upload(&self, bearer: Option<&str>, bytes: &[u8]) -> Result<String, Failure> {
        let digest = sha256(bytes);
        let mut beginning = self.agent.post(&self.url("blobs/uploads/"));

        if let Some(bearer) = bearer {
            beginning = beginning.header("Authorization", bearer);
        }

        let begun = beginning
            .send_empty()
            .map_err(|error| Failure::Io(format!("cannot begin an upload: {error}")))?;
        let Some(location) = begun
            .headers()
            .get("location")
            .and_then(|value| value.to_str().ok())
        else {
            return Err(Failure::Io(format!(
                "the registry began no upload: {}",
                begun.status()
            )));
        };
        let at = if location.starts_with('/') {
            format!("https://{}{location}", self.host)
        } else {
            location.to_owned()
        };
        let joined = if at.contains('?') { '&' } else { '?' };
        let mut put = self
            .agent
            .put(&format!("{at}{joined}digest={digest}"))
            .header("Content-Type", "application/octet-stream");

        if let Some(bearer) = bearer {
            put = put.header("Authorization", bearer);
        }

        let response = put
            .send(bytes)
            .map_err(|error| Failure::Io(format!("cannot upload {digest}: {error}")))?;

        if response.status().is_success() {
            Ok(digest)
        } else {
            Err(Failure::Io(format!(
                "the registry refused {digest}: {}",
                response.status()
            )))
        }
    }

    /// What the registry answers at a URL, at most this many bytes; none where it holds nothing there.
    fn get(&self, url: &str, bearer: Option<&str>, most: u64) -> Result<Option<Vec<u8>>, Failure> {
        let mut get = self.agent.get(url).header("Accept", MANIFEST);

        if let Some(bearer) = bearer {
            get = get.header("Authorization", bearer);
        }

        let mut response = get
            .call()
            .map_err(|error| Failure::Io(format!("cannot read {url}: {error}")))?;

        if response.status().as_u16() == 404 {
            return Ok(None);
        }

        if !response.status().is_success() {
            return Err(Failure::Io(format!(
                "the registry answered {} at {url}",
                response.status()
            )));
        }

        let bytes = response
            .body_mut()
            .with_config()
            .limit(most)
            .read_to_vec()
            .map_err(|error| Failure::Io(format!("cannot read {url}: {error}")))?;

        Ok(Some(bytes))
    }

    /// The bearer a request of this access carries, from the registry's token service; none where it asks none.
    fn bearer(&self, access: Access) -> Result<Option<String>, Failure> {
        let response = self
            .agent
            .get(&format!("https://{}/v2/", self.host))
            .call()
            .map_err(|error| Failure::Io(format!("cannot reach {}: {error}", self.host)))?;

        if response.status().as_u16() != 401 {
            return Ok(None);
        }

        let Some(challenge) = response
            .headers()
            .get("www-authenticate")
            .and_then(|value| value.to_str().ok())
        else {
            return Err(Failure::Io(format!(
                "{} asks for credentials it does not name",
                self.host
            )));
        };
        let realm = parameter(challenge, "realm").unwrap_or_default();
        let service = parameter(challenge, "service").unwrap_or_default();
        let mut asked = self
            .agent
            .get(&realm)
            .query("service", &service)
            .query("scope", access.scope(&self.name));

        if let Some(token) = &self.token {
            asked = asked.header(
                "Authorization",
                &format!("Basic {}", base64(format!("token:{token}").as_bytes())),
            );
        }

        let mut answered = asked
            .call()
            .map_err(|error| Failure::Io(format!("cannot ask {realm} for a token: {error}")))?;

        if !answered.status().is_success() {
            return Err(Failure::Io(format!(
                "{realm} gave no token: {}",
                answered.status()
            )));
        }

        let body = answered
            .body_mut()
            .with_config()
            .limit(MANIFEST_MOST)
            .read_to_vec()
            .map_err(|error| Failure::Io(format!("cannot read {realm}'s token: {error}")))?;

        token_in(&body).map(|token| Some(format!("Bearer {token}")))
    }
}

/// The digest of a manifest's first layer.
fn layer_of(manifest: &[u8]) -> Result<String, Failure> {
    let refused = |_| Failure::Refused("the registry's manifest is not one of a pack".to_owned());
    let mut json = Cursor::over(manifest).map_err(refused)?;
    let mut members = json.object().map_err(refused)?;
    let mut digest = None;

    while let Some(key) = members.next(&mut json).map_err(refused)? {
        if key.text == "layers" {
            let mut layers = json.array().map_err(refused)?;

            while layers.next(&mut json).map_err(refused)? {
                let mut layer = json.object().map_err(refused)?;

                while let Some(field) = layer.next(&mut json).map_err(refused)? {
                    if field.text == "digest" && digest.is_none() {
                        digest = Some(json.string().map_err(refused)?);
                    } else {
                        json.skip().map_err(refused)?;
                    }
                }
            }
        } else {
            json.skip().map_err(refused)?;
        }
    }

    digest.ok_or_else(|| Failure::Refused("the registry's manifest holds no layer".to_owned()))
}

/// The token a token service answered, as `token` or `access_token`.
fn token_in(body: &[u8]) -> Result<String, Failure> {
    let refused = |_| Failure::Refused("the token service answered no token".to_owned());
    let mut json = Cursor::over(body).map_err(refused)?;
    let mut members = json.object().map_err(refused)?;
    let mut token = None;

    while let Some(key) = members.next(&mut json).map_err(refused)? {
        if (key.text == "token" || key.text == "access_token") && token.is_none() {
            token = Some(json.string().map_err(refused)?);
        } else {
            json.skip().map_err(refused)?;
        }
    }

    token.ok_or_else(|| Failure::Refused("the token service answered no token".to_owned()))
}

/// A parameter of a `WWW-Authenticate` challenge, unquoted.
fn parameter(challenge: &str, name: &str) -> Option<String> {
    let start = challenge.find(&format!("{name}=\""))? + name.len() + 2;
    let rest = challenge.get(start..)?;
    let end = rest.find('"')?;

    rest.get(..end).map(str::to_owned)
}

fn sha256(bytes: &[u8]) -> String {
    let digest = Sha256::digest(bytes);
    let hex = digest.iter().fold(String::new(), |mut hex, byte| {
        let _ = write!(hex, "{byte:02x}");
        hex
    });

    format!("sha256:{hex}")
}

fn base64(bytes: &[u8]) -> String {
    const ALPHABET: &[u8; 64] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    let mut text = String::new();

    for chunk in bytes.chunks(3) {
        let first = chunk.first().copied().unwrap_or_default();
        let second = chunk.get(1).copied().unwrap_or_default();
        let third = chunk.get(2).copied().unwrap_or_default();
        let joined = (u32::from(first) << 16) | (u32::from(second) << 8) | u32::from(third);

        for at in 0..4 {
            if at <= chunk.len() {
                let index = (joined >> (18 - 6 * at)) & 63;
                text.push(char::from(
                    ALPHABET.get(index as usize).copied().unwrap_or(b'='),
                ));
            } else {
                text.push('=');
            }
        }
    }

    text
}

#[cfg(test)]
mod tests {
    use super::{base64, layer_of, parameter, sha256, token_in};

    #[test]
    fn encodes_base64_as_the_standard_does() {
        assert_eq!(base64(b"token:abc"), "dG9rZW46YWJj");
        assert_eq!(base64(b"ab"), "YWI=");
        assert_eq!(base64(b"a"), "YQ==");
    }

    #[test]
    fn reads_a_challenge_s_parameters() {
        let challenge =
            r#"Bearer realm="https://ghcr.io/token",service="ghcr.io",scope="repository:o/p:pull""#;

        assert_eq!(
            parameter(challenge, "realm").as_deref(),
            Some("https://ghcr.io/token")
        );
        assert_eq!(parameter(challenge, "service").as_deref(), Some("ghcr.io"));
    }

    #[test]
    fn reads_a_manifest_s_layer_and_a_token() {
        let manifest = br#"{"schemaVersion":2,"config":{"digest":"sha256:c"},"layers":[{"mediaType":"x","digest":"sha256:l","size":1}]}"#;

        assert_eq!(layer_of(manifest).ok().as_deref(), Some("sha256:l"));
        assert_eq!(
            token_in(br#"{"access_token":"t"}"#).ok().as_deref(),
            Some("t")
        );
    }

    #[test]
    fn digests_the_empty_config_as_oci_names_it() {
        assert_eq!(
            sha256(b"{}"),
            "sha256:44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a"
        );
    }
}
