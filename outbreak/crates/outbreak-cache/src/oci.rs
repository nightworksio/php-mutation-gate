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

use crate::pack::{self, Pack};
use crate::request::Request;
use crate::{Failure, entries, write};

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
    pulled(request, &Registry::named(request)?)
}

fn pulled(request: &Request, registry: &Registry) -> Result<usize, Failure> {
    let mut pulled = 0;

    for (class, into) in &request.packs {
        if let Some(found) = registry.pack(request, class, Access::Pull)? {
            write(into, &found.bytes(request.limits)?)?;
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
    pushed(request, &Registry::named(request)?)
}

fn pushed(request: &Request, registry: &Registry) -> Result<usize, Failure> {
    let mut pushed = 0;

    for (class, path) in &request.packs {
        let newer = entries(request, class, path)?;
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
    origin: String,
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

        Ok(Self::at(
            format!("https://{host}"),
            name,
            env::var(TOKEN).unwrap_or_default(),
        ))
    }

    /// The repository of this name at a registry's origin, asked with this token where it asks one and the token
    /// is not empty.
    fn at(origin: String, name: &str, token: String) -> Self {
        let config = Agent::config_builder()
            .http_status_as_error(false)
            .timeout_global(Some(TIMEOUT))
            .build();

        Self {
            agent: config.into(),
            origin,
            name: name.to_owned(),
            token: (!token.is_empty()).then_some(token),
        }
    }

    fn url(&self, path: &str) -> String {
        format!("{}/v2/{}/{path}", self.origin, self.name)
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

        Ok(request.holds(&read, class).then_some(read))
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
            format!("{}{location}", self.origin)
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
            .get(&format!("{}/v2/", self.origin))
            .call()
            .map_err(|error| Failure::Io(format!("cannot reach {}: {error}", self.origin)))?;

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
                self.origin
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
    use std::collections::HashMap;
    use std::fmt::Write as _;
    use std::io::{BufRead as _, BufReader, Read as _, Write as _};
    use std::net::TcpListener;
    use std::sync::Mutex;

    use super::{
        Registry, base64, layer_of, parameter, pull, pulled, push, pushed, sha256, token_in,
    };
    use crate::Failure;
    use crate::request::{Limits, Request, Store};
    use crate::tests::{packed, request, scratch};

    /// A request as the registry read it.
    struct Asked {
        method: String,
        target: String,
        headers: HashMap<String, String>,
        body: Vec<u8>,
    }

    impl Asked {
        fn header(&self, name: &str) -> Option<&str> {
            self.headers.get(name).map(String::as_str)
        }

        /// The part of the target's path after `/v2/`'s first name.
        fn scenario(&self) -> &str {
            self.target.split('/').nth(2).unwrap()
        }
    }

    /// A status, its headers and a body.
    type Answer = (u16, Vec<(&'static str, String)>, Vec<u8>);

    /// The status of an answer never sent: the connection is dropped.
    const DROPPED: u16 = 0;

    /// A registry on a port of its own, answering each request as `answer` does given its origin: the origin.
    fn serve(answer: impl Fn(&Asked, &str) -> Answer + Send + 'static) -> String {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let origin = format!("http://{}", listener.local_addr().unwrap());
        let at = origin.clone();

        std::thread::spawn(move || {
            for stream in listener.incoming() {
                let mut stream = stream.unwrap();
                let mut reader = BufReader::new(stream.try_clone().unwrap());
                let mut line = String::new();
                reader.read_line(&mut line).unwrap();
                let mut words = line.split_whitespace();
                let (method, target) = (words.next().unwrap(), words.next().unwrap());
                let mut headers = HashMap::new();

                loop {
                    let mut header = String::new();
                    reader.read_line(&mut header).unwrap();
                    let Some((name, value)) = header.trim_end().split_once(':') else {
                        break;
                    };
                    headers.insert(name.to_ascii_lowercase(), value.trim().to_owned());
                }

                let length = headers
                    .get("content-length")
                    .map_or(0, |length| length.parse().unwrap());
                let mut body = vec![0; length];
                reader.read_exact(&mut body).unwrap();
                let asked = Asked {
                    method: method.to_owned(),
                    target: target.to_owned(),
                    headers,
                    body,
                };
                let (status, headers, body) = answer(&asked, &at);

                if status == DROPPED {
                    continue;
                }

                let mut head = format!(
                    "HTTP/1.1 {status} X\r\nContent-Length: {}\r\nConnection: close\r\n",
                    body.len()
                );

                for (name, value) in headers {
                    let _ = write!(head, "{name}: {value}\r\n");
                }

                head.push_str("\r\n");
                stream.write_all(head.as_bytes()).unwrap();
                stream.write_all(&body).unwrap();
            }
        });

        origin
    }

    /// A registry that keeps what is pushed to it, asking a token for `secret` where it asks one.
    fn registry(asks: bool) -> String {
        let kept: Mutex<HashMap<String, Vec<u8>>> = Mutex::default();

        serve(move |asked, origin| {
            let path = asked.target.split('?').next().unwrap();

            if path == "/v2/" {
                return if asks {
                    let challenge = format!(r#"Bearer realm="{origin}/token",service="fake""#);
                    (401, vec![("WWW-Authenticate", challenge)], Vec::new())
                } else {
                    (200, Vec::new(), Vec::new())
                };
            }

            if path == "/token" {
                assert_eq!(
                    asked.header("authorization"),
                    Some(format!("Basic {}", base64(b"token:secret")).as_str())
                );
                assert!(asked.target.contains("service=fake"));
                return (
                    200,
                    Vec::new(),
                    br#"{"expires_in":300,"token":"t"}"#.to_vec(),
                );
            }

            assert_eq!(asked.header("authorization"), asks.then_some("Bearer t"));
            let mut kept = kept.lock().unwrap();

            match asked.method.as_str() {
                "POST" => {
                    let location = if asks {
                        "/v2/o/p/blobs/uploads/1?state=s".to_owned()
                    } else {
                        format!("{origin}/v2/o/p/blobs/uploads/2")
                    };
                    (202, vec![("Location", location)], Vec::new())
                }
                "PUT" if path.contains("/uploads/") => {
                    let digest = asked.target.split("digest=").nth(1).unwrap();
                    assert_eq!(sha256(&asked.body), digest.replace("%3A", ":"));
                    kept.insert(
                        format!("/v2/o/p/blobs/{}", digest.replace("%3A", ":")),
                        asked.body.clone(),
                    );
                    (201, Vec::new(), Vec::new())
                }
                "PUT" => {
                    kept.insert(path.to_owned(), asked.body.clone());
                    (201, Vec::new(), Vec::new())
                }
                _ => kept
                    .get(path)
                    .map_or((404, Vec::new(), Vec::new()), |body| {
                        (200, Vec::new(), body.clone())
                    }),
            }
        })
    }

    fn pushing(directory: &std::path::Path, key: &str) -> Request {
        let entries = directory.join(format!("{key}.pack"));
        std::fs::write(&entries, packed("trusted", "base1", key)).unwrap();

        request(Store::Oci, &entries.to_string_lossy())
    }

    #[test]
    fn pushes_and_pulls_through_a_registry_that_asks_a_token() {
        let directory = scratch("oci-token");
        let at = Registry::at(registry(true), "o/p", "secret".to_owned());
        let into = directory.join("into/trusted.pack");
        let mut pulling = request(Store::Oci, &into.to_string_lossy());

        assert_eq!(pulled(&pulling, &at), Ok(0));
        assert_eq!(pushed(&pushing(&directory, "a"), &at), Ok(1));
        assert_eq!(pushed(&pushing(&directory, "b"), &at), Ok(1));
        assert_eq!(pulled(&pulling, &at), Ok(1));
        assert_eq!(
            crate::pack::read(&std::fs::read(&into).unwrap(), pulling.limits)
                .unwrap()
                .len(),
            2
        );

        pulling.newest = true;
        pulling.base = String::new();

        assert_eq!(pulled(&pulling, &at), Ok(1));
    }

    #[test]
    fn answers_without_credentials_where_the_registry_asks_none() {
        let directory = scratch("oci-open");
        let at = Registry::at(registry(false), "o/p", String::new());
        let into = directory.join("trusted.pack");
        let mut pulling = request(Store::Oci, &into.to_string_lossy());

        assert_eq!(pushed(&pushing(&directory, "a"), &at), Ok(1));
        assert_eq!(pulled(&pulling, &at), Ok(1));

        pulling.base = "base0".to_owned();

        assert_eq!(pulled(&pulling, &at), Ok(0));
    }

    fn failed(origin: &str, name: &str, asked: &Request, push: bool) -> String {
        let at = Registry::at(origin.to_owned(), name, String::new());
        let done = if push {
            pushed(asked, &at)
        } else {
            pulled(asked, &at)
        };

        done.unwrap_err().to_string()
    }

    #[test]
    fn fails_where_the_registry_asks_credentials_it_gives_no_token_for() {
        let pulling = request(Store::Oci, "/nowhere");
        let unnamed = serve(|_, _| (401, Vec::new(), Vec::new()));
        let challenging = |status: u16, body: Vec<u8>| {
            serve(move |asked, origin| {
                if asked.target == "/v2/" {
                    let challenge = format!(r#"Bearer realm="{origin}/token",service="fake""#);
                    (401, vec![("WWW-Authenticate", challenge)], Vec::new())
                } else {
                    (status, Vec::new(), body.clone())
                }
            })
        };
        let long = vec![b' '; usize::try_from(super::MANIFEST_MOST).unwrap() + 1];

        assert!(
            failed(&unnamed, "o/p", &pulling, false)
                .ends_with("asks for credentials it does not name")
        );
        assert!(
            failed(&challenging(403, Vec::new()), "o/p", &pulling, false)
                .ends_with("gave no token: 403 Forbidden")
        );
        assert!(
            failed(&challenging(DROPPED, Vec::new()), "o/p", &pulling, false)
                .contains("for a token")
        );
        assert!(failed(&challenging(200, long), "o/p", &pulling, false).contains("'s token"));
        assert!(failed("http://127.0.0.1:1", "o/p", &pulling, false).starts_with("cannot reach"));
    }

    #[test]
    fn fails_where_the_registry_answers_no_pack_or_refuses_one() {
        let held = packed("trusted", "base1", "a");
        let digest = sha256(&held);
        let origin = serve(move |asked, origin| {
            let manifest =
                |digest: &str| format!(r#"{{"layers":[{{"digest":"{digest}"}}]}}"#).into_bytes();
            let path = asked.target.split('?').next().unwrap();

            match (asked.scenario(), asked.method.as_str()) {
                (_, "GET") if path == "/v2/" => (200, Vec::new(), Vec::new()),
                ("status", _) => (500, Vec::new(), Vec::new()),
                ("dropped", _) | ("unbegun", "POST") | ("unsent", "PUT") => {
                    (DROPPED, Vec::new(), Vec::new())
                }
                ("unpacked", _) => (200, Vec::new(), b"[]".to_vec()),
                ("blobless" | "big", "GET") if path.contains("/manifests/") => {
                    (200, Vec::new(), manifest(&digest))
                }
                ("other", "GET") if path.contains("/manifests/") => {
                    (200, Vec::new(), manifest("sha256:0"))
                }
                ("other" | "big", "GET") => (200, Vec::new(), held.clone()),
                (_, "POST") if asked.scenario() != "unlocated" => (
                    202,
                    vec![(
                        "Location",
                        format!("{origin}/v2/{}/x/upload", asked.scenario()),
                    )],
                    Vec::new(),
                ),
                (scenario, "PUT") if path.ends_with("/upload") && scenario != "refused" => {
                    (201, Vec::new(), Vec::new())
                }
                (scenario, "PUT") => {
                    let status = if scenario == "untaggable" {
                        DROPPED
                    } else {
                        400
                    };
                    (status, Vec::new(), Vec::new())
                }
                _ => (404, Vec::new(), Vec::new()),
            }
        });
        let directory = scratch("oci-failing");
        let pulling = request(Store::Oci, &directory.join("into.pack").to_string_lossy());
        let small = Request {
            limits: Limits {
                packed: 10,
                unpacked: 10,
            },
            ..pulling.clone()
        };
        let pushes = pushing(&directory, "a");

        assert!(
            failed(&origin, "status/x", &pulling, false).starts_with("the registry answered 500")
        );
        assert!(failed(&origin, "dropped/x", &pulling, false).starts_with("cannot read"));
        assert!(failed(&origin, "unbegun/x", &pushes, true).starts_with("cannot begin an upload"));
        assert!(failed(&origin, "unsent/x", &pushes, true).starts_with("cannot upload"));
        assert!(failed(&origin, "untaggable/x", &pushes, true).starts_with("cannot tag"));
        assert_eq!(
            failed(&origin, "unpacked/x", &pulling, false),
            "the registry's manifest is not one of a pack"
        );
        assert!(
            failed(&origin, "blobless/x", &pulling, false)
                .starts_with("the registry holds no blob")
        );
        assert!(
            failed(&origin, "other/x", &pulling, false)
                .starts_with("the blob the registry answered is not")
        );
        assert!(failed(&origin, "big/x", &small, false).starts_with("cannot read"));
        assert!(
            failed(&origin, "unlocated/x", &pushes, true)
                .starts_with("the registry began no upload")
        );
        assert!(
            failed(&origin, "refused/x", &pushes, true).starts_with("the registry refused sha256:")
        );
        assert!(
            failed(&origin, "untagged/x", &pushes, true)
                .starts_with("the registry refused the tag")
        );
    }

    #[test]
    fn reaches_the_registry_the_request_names() {
        let mut asked = request(Store::Oci, "/nowhere/entries.pack");
        let refused = |why: &str| Err(Failure::Refused(why.to_owned()));

        assert_eq!(pull(&asked), refused("the request names no registry"));
        assert_eq!(push(&asked), refused("the request names no registry"));

        asked.registry = Some("https://ghcr.io/o/p".to_owned());

        assert_eq!(
            pull(&asked),
            refused("https://ghcr.io/o/p names no repository of a registry")
        );

        asked.registry = Some("127.0.0.1:1/o/p".to_owned());

        assert!(
            matches!(pull(&asked), Err(Failure::Io(why)) if why.starts_with("cannot reach https://127.0.0.1:1"))
        );
        assert!(
            matches!(push(&asked), Err(Failure::Io(why)) if why.starts_with("cannot read /nowhere"))
        );
    }

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
        assert!(layer_of(br#"{"layers":[]}"#).is_err());
        assert_eq!(
            token_in(br#"{"access_token":"t"}"#).ok().as_deref(),
            Some("t")
        );
        assert!(token_in(b"{}").is_err());
        assert!(token_in(b"[").is_err());
    }

    #[test]
    fn digests_the_empty_config_as_oci_names_it() {
        assert_eq!(
            sha256(b"{}"),
            "sha256:44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a"
        );
    }
}
